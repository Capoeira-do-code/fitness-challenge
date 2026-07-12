<?php

declare(strict_types=1);

/**
 * Notion integration (Phase 1: one-way push of daily training logs app -> Notion).
 *
 * The app talks to the Notion REST API directly with an internal integration
 * token stored in app_settings. This module is inert until the admin configures
 * a token + database id and enables it, so a fresh/unconfigured install does
 * nothing and never errors.
 *
 * A local notion_sync_state table maps each daily_logs row to the Notion page it
 * produced (plus a content hash) so pushes are idempotent and unchanged rows are
 * skipped. That mapping is also what a later Notion -> app direction will build on.
 */

const NOTION_API_BASE = 'https://api.notion.com/v1';
const NOTION_API_VERSION = '2022-06-28';
const NOTION_SYNC_BATCH_LIMIT = 60;
const NOTION_PULL_MAX_PAGES = 25; // up to 2500 Notion rows scanned per pull run

/** Notion property names the app writes to (the DB must contain matching props). */
const NOTION_PROPERTY_MAP = [
    // field key => [notion property name, expected notion type]
    'date' => ['Date', 'date'],
    'user' => ['User', 'rich_text'],
    'steps' => ['Steps', 'number'],
    'distance_km' => ['Distance km', 'number'],
    'workout_done' => ['Workout', 'checkbox'],
    'workout_type' => ['Workout type', 'rich_text'],
    'weight' => ['Weight', 'number'],
    'notes' => ['Notes', 'rich_text'],
    'log_id' => ['Log ID', 'number'],
];

/** App fields available for mapping, in display order, with their i18n label keys. */
function notion_field_labels(): array
{
    return [
        'date' => t('common.date'),
        'user' => t('common.user'),
        'steps' => t('metric.steps'),
        'distance_km' => t('metric.distance_km'),
        'workout_done' => t('table.completed_workout'),
        'workout_type' => t('table.primary_workout_type'),
        'weight' => t('metric.weight'),
        'notes' => t('common.notes'),
        'log_id' => t('admin.notion_field_log_id'),
    ];
}

/** Default field -> Notion property name map (derived from NOTION_PROPERTY_MAP). */
function notion_default_field_map(): array
{
    $map = [];
    foreach (NOTION_PROPERTY_MAP as $field => [$propName]) {
        $map[$field] = $propName;
    }

    return $map;
}

/**
 * Configured field -> Notion property name map. Admin choices are stored as JSON
 * in notion_field_map and merged over the defaults; a blank value means "do not
 * sync this field".
 */
function notion_field_map(PDO $pdo): array
{
    $defaults = notion_default_field_map();
    $stored = json_decode((string) (app_setting($pdo, 'notion_field_map', '') ?? ''), true);
    if (!is_array($stored)) {
        return $defaults;
    }

    $map = [];
    foreach (array_keys($defaults) as $field) {
        $map[$field] = array_key_exists($field, $stored) ? trim((string) $stored[$field]) : $defaults[$field];
    }

    return $map;
}

/** Subset of the field map whose target property actually exists in the schema. */
function notion_active_field_map(array $fieldMap, array $schema): array
{
    $present = is_array($schema['present'] ?? null) ? $schema['present'] : [];
    $active = [];
    foreach ($fieldMap as $field => $propName) {
        $propName = trim((string) $propName);
        if ($propName !== '' && isset($present[$propName])) {
            $active[$field] = $propName;
        }
    }

    return $active;
}

/** Cached Notion database schema (property name => type), refreshed on demand. */
function notion_schema_cache(PDO $pdo): array
{
    $cached = json_decode((string) (app_setting($pdo, 'notion_schema_cache', '') ?? ''), true);

    return is_array($cached) ? $cached : [];
}

function notion_save_field_map(PDO $pdo, array $input, int $actorUserId): void
{
    $raw = is_array($input['notion_map'] ?? null) ? (array) $input['notion_map'] : [];
    $map = [];
    foreach (array_keys(notion_default_field_map()) as $field) {
        $map[$field] = trim((string) ($raw[$field] ?? ''));
    }
    set_app_setting($pdo, 'notion_field_map', (string) json_encode($map, JSON_UNESCAPED_UNICODE), $actorUserId);
}

/**
 * Fetch the target database schema and cache it (property name => type) so the
 * mapping UI can offer the real property names without a blocking call on every
 * admin render.
 *
 * @return array{ok:bool,error:string,count:int}
 */
function notion_refresh_schema_cache(PDO $pdo, int $actorUserId): array
{
    $settings = notion_settings($pdo);
    if (!notion_is_configured($settings)) {
        return ['ok' => false, 'error' => 'not_configured', 'count' => 0];
    }
    $schema = notion_fetch_schema($settings);
    if (!$schema['ok']) {
        return ['ok' => false, 'error' => $schema['error'], 'count' => 0];
    }
    $present = is_array($schema['present'] ?? null) ? $schema['present'] : [];
    set_app_setting($pdo, 'notion_schema_cache', (string) json_encode($present, JSON_UNESCAPED_UNICODE), $actorUserId);

    return ['ok' => true, 'error' => '', 'count' => count($present)];
}

function notion_settings(PDO $pdo): array
{
    return [
        'enabled' => notion_bool((string) (app_setting($pdo, 'notion_enabled', '0') ?? '0')),
        'external' => notion_bool((string) (app_setting($pdo, 'notion_external_sync', '0') ?? '0')),
        'token' => trim((string) (app_setting($pdo, 'notion_token', '') ?? '')),
        'database_id' => trim((string) (app_setting($pdo, 'notion_database_id', '') ?? '')),
        'parent_page_id' => trim((string) (app_setting($pdo, 'notion_parent_page_id', '') ?? '')),
        'oauth_client_id' => trim((string) (app_setting($pdo, 'notion_oauth_client_id', '') ?? '')),
        'oauth_client_secret' => trim((string) (app_setting($pdo, 'notion_oauth_client_secret', '') ?? '')),
        'workspace_name' => trim((string) (app_setting($pdo, 'notion_workspace_name', '') ?? '')),
        'base_url' => rtrim(trim((string) (app_setting($pdo, 'app_base_url', '') ?? '')), '/'),
        'frequency' => notion_normalize_frequency((string) (app_setting($pdo, 'notion_sync_frequency', 'off') ?? 'off')),
        'direction' => notion_normalize_direction((string) (app_setting($pdo, 'notion_sync_direction', 'push_only') ?? 'push_only')),
        'run_time' => trim((string) (app_setting($pdo, 'notion_sync_run_time', '03:00') ?? '03:00')),
        'last_sync_at' => trim((string) (app_setting($pdo, 'notion_last_sync_at', '') ?? '')),
        'last_status' => trim((string) (app_setting($pdo, 'notion_last_status', '') ?? '')),
        'last_summary' => trim((string) (app_setting($pdo, 'notion_last_summary', '') ?? '')),
    ];
}

function notion_bool(string $value): bool
{
    return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
}

function notion_normalize_frequency(string $value): string
{
    $value = strtolower(trim($value));

    // Scheduled runs reuse the daily backup slot pattern (a fixed run_time each
    // day), so only off/daily are honest options here.
    return in_array($value, ['off', 'daily'], true) ? $value : 'off';
}

function notion_normalize_direction(string $value): string
{
    $value = strtolower(trim($value));

    return in_array($value, ['push_only', 'two_way'], true) ? $value : 'push_only';
}

/** Fields safe to pull Notion -> app (scalar columns that map cleanly). */
function notion_pull_updatable_fields(): array
{
    return ['steps', 'distance_km', 'weight', 'notes', 'workout_done'];
}

function notion_is_configured(array $settings): bool
{
    return ($settings['token'] ?? '') !== '' && ($settings['database_id'] ?? '') !== '';
}

function notion_is_enabled(array $settings): bool
{
    return !empty($settings['enabled']) && notion_is_configured($settings);
}

function notion_ensure_schema(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS notion_sync_state (
            log_id INTEGER PRIMARY KEY,
            user_id INTEGER,
            notion_page_id TEXT,
            content_hash TEXT,
            synced_at TEXT
        )'
    );
}

function notion_update_settings(PDO $pdo, array $input, int $actorUserId): void
{
    $token = trim((string) ($input['notion_token'] ?? ''));
    // Preserve the stored token when the field is left blank (masked in the UI).
    if ($token === '') {
        $token = trim((string) (app_setting($pdo, 'notion_token', '') ?? ''));
    }

    set_app_setting($pdo, 'notion_enabled', !empty($input['notion_enabled']) ? '1' : '0', $actorUserId);
    set_app_setting($pdo, 'notion_external_sync', !empty($input['notion_external_sync']) ? '1' : '0', $actorUserId);
    set_app_setting($pdo, 'notion_token', $token, $actorUserId);
    set_app_setting($pdo, 'notion_database_id', trim((string) ($input['notion_database_id'] ?? '')), $actorUserId);
    if (array_key_exists('notion_parent_page_id', $input)) {
        set_app_setting($pdo, 'notion_parent_page_id', trim((string) $input['notion_parent_page_id']), $actorUserId);
    }
    if (array_key_exists('notion_oauth_client_id', $input)) {
        set_app_setting($pdo, 'notion_oauth_client_id', trim((string) $input['notion_oauth_client_id']), $actorUserId);
    }
    $oauthSecret = trim((string) ($input['notion_oauth_client_secret'] ?? ''));
    if ($oauthSecret !== '') {
        set_app_setting($pdo, 'notion_oauth_client_secret', $oauthSecret, $actorUserId);
    }
    set_app_setting($pdo, 'notion_sync_frequency', notion_normalize_frequency((string) ($input['notion_sync_frequency'] ?? 'off')), $actorUserId);
    set_app_setting($pdo, 'notion_sync_direction', notion_normalize_direction((string) ($input['notion_sync_direction'] ?? 'push_only')), $actorUserId);
    set_app_setting($pdo, 'notion_sync_run_time', notion_normalize_time((string) ($input['notion_sync_run_time'] ?? '03:00')), $actorUserId);
}

function notion_normalize_time(string $value): string
{
    $value = trim($value);
    if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $value) === 1) {
        return $value;
    }

    return '03:00';
}

/**
 * Low-level Notion API call. Uses curl when available, otherwise a stream
 * context (allow_url_fopen), so it works in the Docker image and local dev.
 *
 * @return array{ok:bool,status:int,data:array,error:string}
 */
function notion_api_request(array $settings, string $method, string $path, ?array $body = null): array
{
    $token = (string) ($settings['token'] ?? '');
    if ($token === '') {
        return ['ok' => false, 'status' => 0, 'data' => [], 'error' => 'missing_token'];
    }

    $url = NOTION_API_BASE . $path;
    $headers = [
        'Authorization: Bearer ' . $token,
        'Notion-Version: ' . NOTION_API_VERSION,
        'Accept: application/json',
    ];
    $payload = null;
    if ($body !== null) {
        $payload = json_encode($body, JSON_UNESCAPED_UNICODE);
        $headers[] = 'Content-Type: application/json';
    }

    // Retry once on rate-limit / transient upstream errors.
    $attempt = 0;
    while (true) {
        if (function_exists('curl_init')) {
            $result = notion_api_request_curl($method, $url, $headers, $payload);
        } else {
            $result = notion_api_request_stream($method, $url, $headers, $payload);
        }
        $status = (int) ($result['status'] ?? 0);
        if ($attempt < 1 && in_array($status, [429, 502, 503, 504], true)) {
            $attempt++;
            usleep(1300000);
            continue;
        }

        return $result;
    }
}

function notion_oauth_configured(array $settings): bool
{
    return ($settings['oauth_client_id'] ?? '') !== '' && ($settings['oauth_client_secret'] ?? '') !== '';
}

/** Callback URL Notion redirects to; must be registered in the public integration. */
function notion_oauth_redirect_uri(array $settings): string
{
    $base = rtrim((string) ($settings['base_url'] ?? ''), '/');

    return $base === '' ? '' : $base . '/?page=notion_oauth_callback';
}

/** Build the Notion authorize URL the admin's browser is sent to. */
function notion_oauth_authorize_url(array $settings, string $state): string
{
    $redirectUri = notion_oauth_redirect_uri($settings);
    if (!notion_oauth_configured($settings) || $redirectUri === '') {
        return '';
    }

    return 'https://api.notion.com/v1/oauth/authorize?' . http_build_query([
        'client_id' => (string) $settings['oauth_client_id'],
        'response_type' => 'code',
        'owner' => 'user',
        'redirect_uri' => $redirectUri,
        'state' => $state,
    ]);
}

/**
 * Exchange an OAuth authorization code for an access token.
 *
 * @return array{ok:bool,access_token:string,workspace_name:string,error:string}
 */
function notion_oauth_exchange_code(array $settings, string $code, string $redirectUri): array
{
    if (!notion_oauth_configured($settings)) {
        return ['ok' => false, 'access_token' => '', 'workspace_name' => '', 'error' => 'OAuth is not configured.'];
    }

    $basic = base64_encode(((string) $settings['oauth_client_id']) . ':' . ((string) $settings['oauth_client_secret']));
    $headers = [
        'Authorization: Basic ' . $basic,
        'Notion-Version: ' . NOTION_API_VERSION,
        'Content-Type: application/json',
        'Accept: application/json',
    ];
    $payload = (string) json_encode([
        'grant_type' => 'authorization_code',
        'code' => $code,
        'redirect_uri' => $redirectUri,
    ]);
    $url = NOTION_API_BASE . '/oauth/token';

    if (function_exists('curl_init')) {
        $response = notion_api_request_curl('POST', $url, $headers, $payload);
    } else {
        $response = notion_api_request_stream('POST', $url, $headers, $payload);
    }
    if (!$response['ok']) {
        return ['ok' => false, 'access_token' => '', 'workspace_name' => '', 'error' => notion_friendly_error($response)];
    }
    $accessToken = (string) ($response['data']['access_token'] ?? '');
    if ($accessToken === '') {
        return ['ok' => false, 'access_token' => '', 'workspace_name' => '', 'error' => 'Notion did not return an access token.'];
    }

    return [
        'ok' => true,
        'access_token' => $accessToken,
        'workspace_name' => (string) ($response['data']['workspace_name'] ?? ''),
        'error' => '',
    ];
}

function notion_oauth_disconnect(PDO $pdo, int $actorUserId): void
{
    set_app_setting($pdo, 'notion_token', '', $actorUserId);
    set_app_setting($pdo, 'notion_workspace_name', '', $actorUserId);
    set_app_setting($pdo, 'notion_database_id', '', $actorUserId);
    set_app_setting($pdo, 'notion_schema_cache', '', $actorUserId);
}

/**
 * Create a fresh Notion database (with the app's default schema) under a parent
 * page shared with the integration. Returns the new database id on success.
 *
 * @return array{ok:bool,database_id:string,error:string}
 */
function notion_create_database(array $settings, string $parentPageId): array
{
    $parentPageId = trim($parentPageId);
    if ($parentPageId === '') {
        return ['ok' => false, 'database_id' => '', 'error' => 'Missing Notion parent page id.'];
    }

    $emptyConfig = new stdClass();
    $properties = [];
    $properties['Name'] = ['title' => $emptyConfig];
    foreach (NOTION_PROPERTY_MAP as [$propName, $propType]) {
        if ($propName === 'Name') {
            continue;
        }
        $properties[$propName] = [$propType => $emptyConfig];
    }

    $response = notion_api_request($settings, 'POST', '/databases', [
        'parent' => ['type' => 'page_id', 'page_id' => $parentPageId],
        'title' => [['type' => 'text', 'text' => ['content' => 'Fitness Challenge']]],
        'properties' => $properties,
    ]);
    if (!$response['ok']) {
        return ['ok' => false, 'database_id' => '', 'error' => notion_friendly_error($response)];
    }
    $databaseId = (string) ($response['data']['id'] ?? '');
    if ($databaseId === '') {
        return ['ok' => false, 'database_id' => '', 'error' => 'Notion did not return a database id.'];
    }

    return ['ok' => true, 'database_id' => $databaseId, 'error' => ''];
}

/** Turn a failed Notion response into a message that guides the admin. */
function notion_friendly_error(array $response): string
{
    $status = (int) ($response['status'] ?? 0);
    $message = trim((string) ($response['error'] ?? ''));

    return match (true) {
        $status === 401 => 'Unauthorized: check the Notion integration token.',
        $status === 404 => 'Not found: open the database in Notion, use ••• → Connections to add your integration, and verify the database id.',
        $status === 429 => 'Rate limited by Notion: wait a moment and run the sync again.',
        $status === 0 => in_array($message, ['', 'request_failed', 'curl_failed'], true)
            ? 'Could not reach Notion (check your network/proxy).'
            : $message,
        default => $message !== '' ? $message : ('HTTP ' . $status),
    };
}

/** @return array{ok:bool,status:int,data:array,error:string} */
function notion_api_request_curl(string $method, string $url, array $headers, ?string $payload): array
{
    $handle = curl_init($url);
    curl_setopt($handle, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($handle, CURLOPT_TIMEOUT, 25);
    if ($payload !== null) {
        curl_setopt($handle, CURLOPT_POSTFIELDS, $payload);
    }
    $raw = curl_exec($handle);
    if ($raw === false) {
        $error = curl_error($handle);
        curl_close($handle);

        return ['ok' => false, 'status' => 0, 'data' => [], 'error' => $error !== '' ? $error : 'curl_failed'];
    }
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);

    return notion_api_finalize($status, is_string($raw) ? $raw : '');
}

/** @return array{ok:bool,status:int,data:array,error:string} */
function notion_api_request_stream(string $method, string $url, array $headers, ?string $payload): array
{
    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $payload ?? '',
            'timeout' => 25,
            'ignore_errors' => true,
        ],
    ]);
    $raw = @file_get_contents($url, false, $context);
    $status = 0;
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $matches) === 1) {
                $status = (int) $matches[1];
            }
        }
    }
    if ($raw === false && $status === 0) {
        return ['ok' => false, 'status' => 0, 'data' => [], 'error' => 'request_failed'];
    }

    return notion_api_finalize($status, is_string($raw) ? $raw : '');
}

/** @return array{ok:bool,status:int,data:array,error:string} */
function notion_api_finalize(int $status, string $raw): array
{
    $data = [];
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $data = $decoded;
        }
    }
    $ok = $status >= 200 && $status < 300;
    $error = '';
    if (!$ok) {
        $error = (string) ($data['message'] ?? ('http_' . $status));
    }

    return ['ok' => $ok, 'status' => $status, 'data' => $data, 'error' => $error];
}

/**
 * Fetch the target database schema so we know the title property name and which
 * of our mapped properties actually exist (send only those to avoid 400s).
 *
 * @return array{ok:bool,error:string,title_prop:string,present:array<string,string>}
 */
function notion_fetch_schema(array $settings): array
{
    $databaseId = (string) ($settings['database_id'] ?? '');
    if ($databaseId === '') {
        return ['ok' => false, 'error' => 'missing_database', 'title_prop' => '', 'present' => []];
    }

    $response = notion_api_request($settings, 'GET', '/databases/' . rawurlencode($databaseId));
    if (!$response['ok']) {
        return ['ok' => false, 'error' => notion_friendly_error($response), 'title_prop' => '', 'present' => []];
    }

    $properties = is_array($response['data']['properties'] ?? null) ? $response['data']['properties'] : [];
    $titleProp = '';
    $present = [];
    foreach ($properties as $name => $definition) {
        $type = (string) ($definition['type'] ?? '');
        if ($type === 'title' && $titleProp === '') {
            $titleProp = (string) $name;
        }
        $present[(string) $name] = $type;
    }

    return ['ok' => true, 'error' => '', 'title_prop' => $titleProp, 'present' => $present];
}

function notion_truncate(string $value, int $limit): string
{
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $limit);
    }

    return substr($value, 0, $limit);
}

function notion_rich_text(string $value): array
{
    $value = trim($value);
    if ($value === '') {
        return [];
    }

    return [['type' => 'text', 'text' => ['content' => notion_truncate($value, 1900)]]];
}

/** Raw app values available for mapping, per daily log. */
function notion_field_values(array $log, array $user): array
{
    $displayName = trim((string) ($user['display_name'] ?? $user['username'] ?? 'User'));

    return [
        'date' => (string) ($log['log_date'] ?? ''),
        'user' => $displayName !== '' ? $displayName : 'User',
        'steps' => $log['steps'] ?? null,
        'distance_km' => $log['distance_km'] ?? null,
        'workout_done' => (int) ($log['workout_done'] ?? 0) === 1,
        'workout_type' => (string) ($log['workout_type'] ?? ''),
        'weight' => $log['weight'] ?? null,
        'notes' => (string) ($log['notes'] ?? ''),
        'log_id' => (int) ($log['id'] ?? 0),
    ];
}

/**
 * Build the Notion properties payload for a daily log using the admin-defined
 * field map. Each field is written to its chosen property and converted to that
 * property's actual Notion type (from the schema); missing/unmapped/blank targets
 * are skipped. The title property is always set to a readable label unless the
 * admin explicitly mapped a field onto it.
 */
function notion_build_properties(array $log, array $user, array $schema, array $fieldMap): array
{
    $present = is_array($schema['present'] ?? null) ? $schema['present'] : [];
    $values = notion_field_values($log, $user);
    $properties = [];

    foreach ($fieldMap as $field => $propName) {
        $propName = trim((string) $propName);
        if ($propName === '' || !array_key_exists($field, $values) || !isset($present[$propName])) {
            continue;
        }
        $value = notion_value_for_type((string) $present[$propName], $values[$field]);
        if ($value !== null) {
            $properties[$propName] = $value;
        }
    }

    $titleProp = (string) ($schema['title_prop'] ?? '');
    if ($titleProp !== '' && !isset($properties[$titleProp])) {
        $displayName = (string) ($values['user'] ?? 'User');
        $properties[$titleProp] = ['title' => notion_rich_text($displayName . ' - ' . (string) ($values['date'] ?? ''))];
    }

    return $properties;
}

/** Convert an app value to the payload shape for a given Notion property type. */
function notion_value_for_type(string $type, mixed $raw): mixed
{
    $string = is_bool($raw) ? ($raw ? '1' : '0') : trim((string) ($raw ?? ''));

    return match ($type) {
        'title' => ['title' => notion_rich_text($string)],
        'rich_text' => ['rich_text' => notion_rich_text($string)],
        'number' => ['number' => $string === '' ? null : (float) $raw],
        'checkbox' => ['checkbox' => notion_truthy($raw)],
        'date' => $string === '' ? null : ['date' => ['start' => $string]],
        'select' => $string === '' ? null : ['select' => ['name' => notion_truncate($string, 100)]],
        'url' => ['url' => $string === '' ? null : $string],
        'email' => ['email' => $string === '' ? null : $string],
        'phone_number' => ['phone_number' => $string === '' ? null : $string],
        default => null,
    };
}

function notion_truthy(mixed $raw): bool
{
    if (is_bool($raw)) {
        return $raw;
    }

    return in_array(strtolower(trim((string) $raw)), ['1', 'true', 'yes', 'on'], true);
}

function notion_content_hash(array $log, array $fieldMap = []): string
{
    // Include the field map so remapping which properties receive data forces a
    // re-push of otherwise-unchanged rows.
    return md5((string) json_encode([
        (string) ($log['log_date'] ?? ''),
        (string) ($log['steps'] ?? ''),
        (string) ($log['distance_km'] ?? ''),
        (int) ($log['workout_done'] ?? 0),
        (string) ($log['workout_type'] ?? ''),
        (string) ($log['weight'] ?? ''),
        (string) ($log['notes'] ?? ''),
        $fieldMap,
    ]));
}

/**
 * Push a single log to Notion (create or update). Returns one of:
 * 'created', 'updated', 'skipped', 'failed'.
 */
function notion_upsert_log(PDO $pdo, array $settings, array $schema, array $fieldMap, array $log, array $user, ?string &$error = null): string
{
    $logId = (int) ($log['id'] ?? 0);
    if ($logId <= 0) {
        return 'skipped';
    }

    $hash = notion_content_hash($log, $fieldMap);
    $state = db_fetch_one($pdo, 'SELECT * FROM notion_sync_state WHERE log_id = :id', [':id' => $logId]);
    $pageId = $state !== null ? trim((string) ($state['notion_page_id'] ?? '')) : '';

    if ($state !== null && $pageId !== '' && (string) ($state['content_hash'] ?? '') === $hash) {
        return 'skipped';
    }

    $properties = notion_build_properties($log, $user, $schema, $fieldMap);
    if ($properties === []) {
        return 'skipped';
    }

    if ($pageId !== '') {
        $response = notion_api_request($settings, 'PATCH', '/pages/' . rawurlencode($pageId), ['properties' => $properties]);
    } else {
        $response = notion_api_request($settings, 'POST', '/pages', [
            'parent' => ['database_id' => (string) $settings['database_id']],
            'properties' => $properties,
        ]);
    }

    if (!$response['ok']) {
        $error = notion_friendly_error($response);
        return 'failed';
    }

    $newPageId = $pageId !== '' ? $pageId : (string) ($response['data']['id'] ?? '');
    if ($newPageId === '') {
        $error = 'Notion did not return a page id.';
        return 'failed';
    }

    notion_store_state($pdo, $logId, (int) ($log['user_id'] ?? ($user['id'] ?? 0)), $newPageId, $hash);

    return $pageId !== '' ? 'updated' : 'created';
}

function notion_store_state(PDO $pdo, int $logId, int $userId, string $pageId, string $hash): void
{
    db_execute(
        $pdo,
        'INSERT INTO notion_sync_state (log_id, user_id, notion_page_id, content_hash, synced_at)
         VALUES (:log_id, :user_id, :page_id, :hash, :synced_at)
         ON CONFLICT(log_id) DO UPDATE SET
            user_id = excluded.user_id,
            notion_page_id = excluded.notion_page_id,
            content_hash = excluded.content_hash,
            synced_at = excluded.synced_at',
        [
            ':log_id' => $logId,
            ':user_id' => $userId,
            ':page_id' => $pageId,
            ':hash' => $hash,
            ':synced_at' => now_iso(),
        ]
    );
}

/** Extract the plain string value from a Notion rich_text/title array. */
function notion_plain_text(array $rich): string
{
    $out = '';
    foreach ($rich as $segment) {
        if (!is_array($segment)) {
            continue;
        }
        $out .= (string) ($segment['plain_text'] ?? ($segment['text']['content'] ?? ''));
    }

    return $out;
}

/** Read an app-usable value out of a Notion property payload, by its type. */
function notion_read_property_value(string $type, array $payload): mixed
{
    return match ($type) {
        'number' => $payload['number'] ?? null,
        'checkbox' => (bool) ($payload['checkbox'] ?? false),
        'title' => notion_plain_text(is_array($payload['title'] ?? null) ? $payload['title'] : []),
        'rich_text' => notion_plain_text(is_array($payload['rich_text'] ?? null) ? $payload['rich_text'] : []),
        'select' => (string) ($payload['select']['name'] ?? ''),
        'date' => (string) ($payload['date']['start'] ?? ''),
        'url' => (string) ($payload['url'] ?? ''),
        'email' => (string) ($payload['email'] ?? ''),
        'phone_number' => (string) ($payload['phone_number'] ?? ''),
        default => null,
    };
}

/**
 * Coerce a Notion value for a pull-updatable field into [column, newValue,
 * currentValue] so callers can compare and update only real changes. Returns
 * null for fields that are not pull-updatable.
 *
 * @return array{0:string,1:mixed,2:mixed}|null
 */
function notion_pull_field_change(string $field, mixed $raw, array $log): ?array
{
    // Empty/absent Notion values are ignored rather than clearing app data, so a
    // Notion column the team leaves blank never wipes what the app already has.
    // (workout_done is a checkbox and always has a definite true/false state.)
    $isEmpty = $raw === null || $raw === '';

    switch ($field) {
        case 'steps':
            if ($isEmpty) {
                return null;
            }

            return ['steps', (int) $raw, (int) ($log['steps'] ?? 0)];
        case 'distance_km':
            if ($isEmpty) {
                return null;
            }
            $current = ($log['distance_km'] ?? null) === null ? null : (float) $log['distance_km'];

            return ['distance_km', (float) $raw, $current];
        case 'weight':
            if ($isEmpty) {
                return null;
            }
            $current = ($log['weight'] ?? null) === null ? null : (float) $log['weight'];

            return ['weight', (float) $raw, $current];
        case 'notes':
            if ($isEmpty) {
                return null;
            }

            return ['notes', (string) $raw, (string) ($log['notes'] ?? '')];
        case 'workout_done':
            return ['workout_done', notion_truthy($raw) ? 1 : 0, (int) ($log['workout_done'] ?? 0)];
        default:
            return null;
    }
}

function notion_apply_log_updates(PDO $pdo, int $logId, array $updates): void
{
    $allowed = notion_pull_updatable_fields();
    $sets = [];
    $params = [':id' => $logId, ':updated_at' => now_iso()];
    foreach ($updates as $column => $value) {
        if (!in_array($column, $allowed, true)) {
            continue;
        }
        $sets[] = "$column = :$column";
        $params[":$column"] = $value;
    }
    if ($sets === []) {
        return;
    }
    $sets[] = 'updated_at = :updated_at';
    db_execute($pdo, 'UPDATE daily_logs SET ' . implode(', ', $sets) . ' WHERE id = :id', $params);
}

/**
 * Apply one Notion page back onto its mapped app log. App wins on conflict: the
 * pull only happens when the app row is unchanged since the last sync and the
 * Notion page was edited more recently. Returns 'updated', 'skipped' or 'failed'.
 */
function notion_pull_page(PDO $pdo, array $page, array $state, array $pullFields, array $present, array $fieldMap): string
{
    $logId = (int) ($state['log_id'] ?? 0);
    if ($logId <= 0) {
        return 'skipped';
    }
    $log = db_fetch_one($pdo, 'SELECT * FROM daily_logs WHERE id = :id', [':id' => $logId]);
    if ($log === null) {
        return 'skipped';
    }

    // Conflict rule (app-first): skip if the app row diverged since last sync.
    if (notion_content_hash($log, $fieldMap) !== (string) ($state['content_hash'] ?? '')) {
        return 'skipped';
    }

    // Only pull when Notion is strictly newer than our last sync of this row.
    $lastEdited = strtotime((string) ($page['last_edited_time'] ?? ''));
    if ($lastEdited === false) {
        return 'skipped';
    }
    $syncedAt = strtotime((string) ($state['synced_at'] ?? ''));
    if ($syncedAt !== false && $lastEdited <= $syncedAt) {
        return 'skipped';
    }

    $properties = is_array($page['properties'] ?? null) ? $page['properties'] : [];
    $updates = [];
    foreach ($pullFields as $field => $propName) {
        $type = (string) ($present[$propName] ?? '');
        $raw = notion_read_property_value($type, is_array($properties[$propName] ?? null) ? $properties[$propName] : []);
        $change = notion_pull_field_change($field, $raw, $log);
        if ($change === null) {
            continue;
        }
        [$column, $newValue, $currentValue] = $change;
        if ($newValue !== $currentValue) {
            $updates[$column] = $newValue;
        }
    }

    if ($updates === []) {
        return 'skipped';
    }

    notion_apply_log_updates($pdo, $logId, $updates);

    $fresh = db_fetch_one($pdo, 'SELECT * FROM daily_logs WHERE id = :id', [':id' => $logId]) ?? $log;
    notion_store_state($pdo, $logId, (int) ($log['user_id'] ?? 0), (string) ($page['id'] ?? ''), notion_content_hash($fresh, $fieldMap));

    return 'updated';
}

/**
 * Pull Notion -> app for rows the app previously created (mapped in
 * notion_sync_state), bounded to $limit applied updates.
 *
 * @return array{pulled:int,checked:int,failed:int,reached_limit:bool}
 */
function notion_pull(PDO $pdo, array $settings, array $schema, array $fieldMap, int $limit): array
{
    $result = ['pulled' => 0, 'checked' => 0, 'failed' => 0, 'reached_limit' => false, 'first_error' => ''];
    $present = is_array($schema['present'] ?? null) ? $schema['present'] : [];
    $active = notion_active_field_map($fieldMap, $schema);
    $pullFields = array_intersect_key($active, array_flip(notion_pull_updatable_fields()));
    if ($pullFields === []) {
        return $result;
    }

    $databaseId = (string) ($settings['database_id'] ?? '');
    $cursor = null;
    $pagesScanned = 0;
    do {
        // Safety cap so a very large external database cannot make one sync run
        // scan forever; anything beyond this is picked up on the next run.
        if ($pagesScanned >= NOTION_PULL_MAX_PAGES) {
            $result['reached_limit'] = true;
            break;
        }
        $pagesScanned++;
        $body = ['page_size' => 100];
        if ($cursor !== null && $cursor !== '') {
            $body['start_cursor'] = $cursor;
        }
        $response = notion_api_request($settings, 'POST', '/databases/' . rawurlencode($databaseId) . '/query', $body);
        if (!$response['ok']) {
            $result['failed']++;
            $result['first_error'] = notion_friendly_error($response);
            break;
        }
        $pages = is_array($response['data']['results'] ?? null) ? $response['data']['results'] : [];
        foreach ($pages as $page) {
            $pageId = (string) ($page['id'] ?? '');
            if ($pageId === '') {
                continue;
            }
            $state = db_fetch_one($pdo, 'SELECT * FROM notion_sync_state WHERE notion_page_id = :pid', [':pid' => $pageId]);
            if ($state === null) {
                continue; // Not app-originated; importing new Notion rows is out of scope for now.
            }
            $result['checked']++;
            $applied = notion_pull_page($pdo, $page, (array) $state, $pullFields, $present, $fieldMap);
            if ($applied === 'updated') {
                $result['pulled']++;
                if ($result['pulled'] >= $limit) {
                    $result['reached_limit'] = true;

                    return $result;
                }
            } elseif ($applied === 'failed') {
                $result['failed']++;
            }
        }
        $cursor = !empty($response['data']['has_more']) ? (string) ($response['data']['next_cursor'] ?? '') : null;
    } while ($cursor !== null && $cursor !== '');

    return $result;
}

/**
 * Push changed rows to Notion. Assumes settings/schema/field map are validated.
 * Bounded to $limit changed rows.
 *
 * @return array{created:int,updated:int,skipped:int,failed:int,reached_limit:bool}
 */
function notion_push(PDO $pdo, array $config, array $settings, array $schema, array $fieldMap, int $limit): array
{
    $result = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'reached_limit' => false, 'first_error' => ''];

    $challenge = function_exists('challenge_settings') ? challenge_settings($pdo, $config) : [];
    $start = to_date((string) ($challenge['challenge_start'] ?? null));
    $end = to_date((string) ($challenge['challenge_end'] ?? null), $start);

    $processed = 0;
    foreach (list_active_users($pdo) as $user) {
        $logs = fetch_logs_for_user_between($pdo, (int) ($user['id'] ?? 0), $start, $end);
        foreach ($logs as $log) {
            $error = null;
            $outcome = notion_upsert_log($pdo, $settings, $schema, $fieldMap, $log, $user, $error);
            if ($outcome === 'skipped') {
                $result['skipped']++;
                continue;
            }
            $processed++;
            $result[$outcome] = ($result[$outcome] ?? 0) + 1;
            if ($outcome === 'failed' && $result['first_error'] === '' && (string) $error !== '') {
                $result['first_error'] = (string) $error;
            }
            if ($processed >= $limit) {
                $result['reached_limit'] = true;

                return $result;
            }
        }
    }

    return $result;
}

/**
 * Full sync entry point: validates config, pulls Notion -> app when the sync
 * direction is two-way, then pushes app -> Notion. Bounded per phase so a web
 * request never runs long.
 *
 * @return array{ok:bool,status:string,created:int,updated:int,skipped:int,failed:int,pulled:int,remaining:int,message:string}
 */
function notion_sync_run(PDO $pdo, array $config, ?int $actorUserId = null, int $limit = NOTION_SYNC_BATCH_LIMIT): array
{
    $settings = notion_settings($pdo);
    $summary = ['ok' => false, 'status' => 'not_configured', 'created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'pulled' => 0, 'remaining' => 0, 'message' => ''];

    if (!notion_is_enabled($settings)) {
        $summary['message'] = 'Notion sync is disabled or not configured.';

        return $summary;
    }

    notion_ensure_schema($pdo);

    $schema = notion_fetch_schema($settings);
    if (!$schema['ok']) {
        $summary['status'] = 'error';
        $summary['message'] = 'Could not read Notion database: ' . $schema['error'];
        notion_record_run($pdo, $summary, $actorUserId);

        return $summary;
    }
    if (($schema['title_prop'] ?? '') === '') {
        $summary['status'] = 'error';
        $summary['message'] = 'The Notion database has no title property.';
        notion_record_run($pdo, $summary, $actorUserId);

        return $summary;
    }

    $fieldMap = notion_field_map($pdo);
    if (notion_active_field_map($fieldMap, $schema) === []) {
        $summary['status'] = 'error';
        $summary['message'] = 'No app fields are mapped to existing Notion properties. Configure the field mapping first.';
        notion_record_run($pdo, $summary, $actorUserId);

        return $summary;
    }

    $reachedLimit = false;
    $firstError = '';

    // Pull first so Notion edits land before we push app state back.
    if (($settings['direction'] ?? 'push_only') === 'two_way') {
        $pull = notion_pull($pdo, $settings, $schema, $fieldMap, $limit);
        $summary['pulled'] = $pull['pulled'];
        $summary['failed'] += $pull['failed'];
        $reachedLimit = $reachedLimit || $pull['reached_limit'];
        if ($firstError === '' && (string) ($pull['first_error'] ?? '') !== '') {
            $firstError = (string) $pull['first_error'];
        }
    }

    $push = notion_push($pdo, $config, $settings, $schema, $fieldMap, $limit);
    $summary['created'] = $push['created'];
    $summary['updated'] = $push['updated'];
    $summary['skipped'] = $push['skipped'];
    $summary['failed'] += $push['failed'];
    $reachedLimit = $reachedLimit || $push['reached_limit'];
    if ($firstError === '' && (string) ($push['first_error'] ?? '') !== '') {
        $firstError = (string) $push['first_error'];
    }

    $summary['ok'] = $summary['failed'] === 0;
    $summary['status'] = $summary['failed'] === 0 ? 'ok' : 'partial';
    $summary['remaining'] = $reachedLimit ? 1 : 0;
    $summary['message'] = sprintf(
        'Pulled %d, created %d, updated %d, skipped %d, failed %d.%s%s',
        $summary['pulled'],
        $summary['created'],
        $summary['updated'],
        $summary['skipped'],
        $summary['failed'],
        $reachedLimit ? ' Batch limit reached - run again to continue.' : '',
        $firstError !== '' ? ' First error: ' . $firstError : ''
    );

    notion_record_run($pdo, $summary, $actorUserId);

    return $summary;
}

function notion_record_run(PDO $pdo, array $summary, ?int $actorUserId): void
{
    $actor = $actorUserId ?? 0;
    set_app_setting_silent($pdo, 'notion_last_sync_at', now_iso(), $actor);
    set_app_setting_silent($pdo, 'notion_last_status', (string) ($summary['status'] ?? ''), $actor);
    set_app_setting_silent($pdo, 'notion_last_summary', (string) ($summary['message'] ?? ''), $actor);
    // Keep the per-run counters so the admin can see what actually moved (#15).
    set_app_setting_silent($pdo, 'notion_last_counts', json_encode([
        'created' => (int) ($summary['created'] ?? 0),
        'updated' => (int) ($summary['updated'] ?? 0),
        'skipped' => (int) ($summary['skipped'] ?? 0),
        'failed' => (int) ($summary['failed'] ?? 0),
        'pulled' => (int) ($summary['pulled'] ?? 0),
        'remaining' => (int) ($summary['remaining'] ?? 0),
    ], JSON_UNESCAPED_SLASHES), $actor);
    // Only overwrite the stored error when this run actually failed, so a
    // successful run clears it and a transient failure stays visible.
    $failed = (string) ($summary['status'] ?? '') === 'error' || (int) ($summary['failed'] ?? 0) > 0;
    set_app_setting_silent($pdo, 'notion_last_error', $failed ? (string) ($summary['message'] ?? '') : '', $actor);
}

/**
 * Sync health for the admin panel (#15): when it last ran, what moved, and
 * whether the last run errored.
 *
 * @return array{last_sync_at:string,status:string,summary:string,error:string,counts:array<string,int>,synced_records:int}
 */
function notion_sync_status(PDO $pdo): array
{
    notion_ensure_schema($pdo);
    $counts = json_decode((string) (app_setting($pdo, 'notion_last_counts', '') ?? ''), true);
    $records = db_fetch_one($pdo, 'SELECT COUNT(*) AS c FROM notion_sync_state');

    $settings = notion_settings($pdo);
    $schema = notion_schema_cache($pdo);
    $fieldMap = notion_field_map($pdo);
    $active = notion_active_field_map($fieldMap, $schema);
    $labels = notion_field_labels();
    $twoWay = $settings['direction'] === 'two_way';
    $pullable = notion_pull_updatable_fields();

    // Which fields actually travel, and which way. A field only syncs if it is
    // mapped AND the mapped Notion property still exists in the cached schema,
    // so a renamed property shows up here as a broken mapping instead of failing
    // silently at push time. When the schema has never been read we cannot know
    // that, so we report the mapping as configured rather than crying wolf.
    $schemaKnown = $schema !== [];
    $fields = [];
    foreach ($labels as $field => $label) {
        $property = (string) ($fieldMap[$field] ?? '');
        $isActive = $schemaKnown ? isset($active[$field]) : $property !== '';
        $fields[] = [
            'field' => $field,
            'label' => $label,
            'property' => $property,
            'active' => $isActive,
            'direction' => !$isActive
                ? 'off'
                : (($twoWay && in_array($field, $pullable, true)) ? 'two_way' : 'push'),
            'missing_property' => $schemaKnown && $property !== '' && !isset($active[$field]),
        ];
    }

    // Records the next run still has to touch. notion_sync_state holds one row
    // per pushed log, so anything logged since the last run is pending.
    $total = db_fetch_one($pdo, 'SELECT COUNT(*) AS c FROM daily_logs');
    $pending = max(0, (int) ($total['c'] ?? 0) - (int) ($records['c'] ?? 0));

    return [
        'last_sync_at' => trim((string) (app_setting($pdo, 'notion_last_sync_at', '') ?? '')),
        'status' => trim((string) (app_setting($pdo, 'notion_last_status', '') ?? '')),
        'summary' => trim((string) (app_setting($pdo, 'notion_last_summary', '') ?? '')),
        'error' => trim((string) (app_setting($pdo, 'notion_last_error', '') ?? '')),
        'counts' => is_array($counts) ? array_map('intval', $counts) : [],
        'synced_records' => (int) ($records['c'] ?? 0),
        'pending_records' => $pending,
        'fields' => $fields,
        'direction' => (string) $settings['direction'],
        'frequency' => (string) $settings['frequency'],
        'run_time' => (string) $settings['run_time'],
        'external' => !empty($settings['external']),
        'schema_known' => $schemaKnown,
    ];
}

/**
 * Scheduler tick (mirrors run_system_backup_scheduler). Runs a bounded push when
 * the configured frequency is due. Failures are swallowed so a Notion outage
 * never breaks page rendering.
 */
function notion_run_scheduler(PDO $pdo, array $config, ?int $actorUserId = null): void
{
    try {
        $settings = notion_settings($pdo);
        if (!notion_is_enabled($settings) || $settings['frequency'] === 'off') {
            return;
        }
        // The standalone Python sync (bin/notion_sync.py) owns scheduled syncs.
        if (!empty($settings['external'])) {
            return;
        }
        if (!function_exists('should_run_scheduled_backup')) {
            return;
        }
        if (!should_run_scheduled_backup($settings['frequency'], (string) $settings['last_sync_at'], (string) $settings['run_time'])) {
            return;
        }
        notion_sync_run($pdo, $config, $actorUserId, NOTION_SYNC_BATCH_LIMIT);
    } catch (Throwable $exception) {
        error_log('notion_run_scheduler: ' . $exception->getMessage());
    }
}
