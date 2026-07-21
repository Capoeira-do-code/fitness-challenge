<?php

declare(strict_types=1);

/**
 * Telegram bot integration: a single bot (admin-configured token) reminds each
 * linked user to log their day and sends motivation. Fully opt-in per user from
 * Settings. Inert until an admin configures and enables a bot token.
 *
 * Linking uses getUpdates polling (no public webhook required, works behind NAT
 * as long as outbound HTTPS works). Sending reminders/motivation is pure
 * outbound. The scheduler tick mirrors run_system_backup_scheduler.
 */

const TELEGRAM_API_BASE = 'https://api.telegram.org';
const TELEGRAM_POLL_MIN_INTERVAL = 45; // seconds between getUpdates polls
const TELEGRAM_MAX_SENDS_PER_TICK = 25; // safety bound per scheduler tick

function telegram_settings(PDO $pdo): array
{
    return [
        'enabled' => telegram_bool((string) (app_setting($pdo, 'telegram_enabled', '0') ?? '0')),
        'external_bot' => telegram_bool((string) (app_setting($pdo, 'telegram_external_bot', '0') ?? '0')),
        'token' => trim((string) (app_setting($pdo, 'telegram_bot_token', '') ?? '')),
        'username' => trim((string) (app_setting($pdo, 'telegram_bot_username', '') ?? '')),
        'offset' => (int) (app_setting($pdo, 'telegram_update_offset', '0') ?? '0'),
        'last_poll_at' => trim((string) (app_setting($pdo, 'telegram_last_poll_at', '') ?? '')),
        'base_url' => rtrim(trim((string) (app_setting($pdo, 'app_base_url', '') ?? '')), '/'),
    ];
}

/** Users linked to Telegram, for the admin management list. */
function telegram_linked_users(PDO $pdo): array
{
    return db_fetch_all(
        $pdo,
        "SELECT id, username, display_name, telegram_chat_id, telegram_reminders_enabled,
                telegram_motivation_enabled, telegram_reminder_time
         FROM users
         WHERE telegram_chat_id IS NOT NULL AND TRIM(telegram_chat_id) <> ''
         ORDER BY display_name COLLATE NOCASE ASC"
    );
}

function telegram_bool(string $value): bool
{
    return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
}

function telegram_is_configured(array $settings): bool
{
    return ($settings['token'] ?? '') !== '';
}

function telegram_is_enabled(array $settings): bool
{
    return !empty($settings['enabled']) && telegram_is_configured($settings);
}

function telegram_redact_secrets(string $message, string ...$secrets): string
{
    foreach ($secrets as $secret) {
        if ($secret !== '') {
            $message = str_replace($secret, '[redacted]', $message);
        }
    }

    return preg_replace(
        '#https://api\.telegram\.org/bot[^/\s]+#i',
        'https://api.telegram.org/bot[redacted]',
        $message
    ) ?? $message;
}

function telegram_update_settings(PDO $pdo, array $input, int $actorUserId): void
{
    $token = trim((string) ($input['telegram_bot_token'] ?? ''));
    if ($token === '') {
        $token = trim((string) (app_setting($pdo, 'telegram_bot_token', '') ?? ''));
    }
    set_app_setting($pdo, 'telegram_enabled', !empty($input['telegram_enabled']) ? '1' : '0', $actorUserId);
    // Ownership is automatic through expiring runtime leases.
    set_app_setting($pdo, 'telegram_external_bot', '0', $actorUserId);
    set_app_setting($pdo, 'telegram_bot_token', $token, $actorUserId);
    set_app_setting($pdo, 'telegram_bot_username', ltrim(trim((string) ($input['telegram_bot_username'] ?? '')), '@'), $actorUserId);
    if (array_key_exists('app_base_url', $input)) {
        set_app_setting($pdo, 'app_base_url', rtrim(trim((string) $input['app_base_url']), '/'), $actorUserId);
    }
}

/**
 * Call the Telegram Bot API. Uses curl when available, otherwise a stream
 * context (allow_url_fopen).
 *
 * @return array{ok:bool,result:mixed,error:string}
 */
function telegram_api_request(string $token, string $method, array $params = []): array
{
    if ($token === '') {
        return ['ok' => false, 'result' => null, 'error' => 'missing_token'];
    }
    $url = TELEGRAM_API_BASE . '/bot' . $token . '/' . $method;
    $payload = (string) json_encode($params, JSON_UNESCAPED_UNICODE);
    $headers = ['Content-Type: application/json', 'Accept: application/json'];

    if (function_exists('curl_init')) {
        $handle = curl_init($url);
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($handle, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_TIMEOUT, 20);
        $raw = curl_exec($handle);
        if ($raw === false) {
            $error = curl_error($handle);
            curl_close($handle);

            return [
                'ok' => false,
                'result' => null,
                'error' => telegram_redact_secrets($error !== '' ? $error : 'curl_failed', $token),
            ];
        }
        curl_close($handle);
    } else {
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $payload,
            'timeout' => 20,
            'ignore_errors' => true,
        ]]);
        $raw = @file_get_contents($url, false, $context);
        if ($raw === false) {
            return ['ok' => false, 'result' => null, 'error' => 'request_failed'];
        }
    }

    $data = json_decode(is_string($raw) ? $raw : '', true);
    if (!is_array($data)) {
        return ['ok' => false, 'result' => null, 'error' => 'bad_response'];
    }
    if (empty($data['ok'])) {
        return [
            'ok' => false,
            'result' => null,
            'error' => telegram_redact_secrets((string) ($data['description'] ?? 'telegram_error'), $token),
        ];
    }

    return ['ok' => true, 'result' => $data['result'] ?? null, 'error' => ''];
}

/** Verify the token and cache the bot username. */
function telegram_verify_bot(PDO $pdo, int $actorUserId): array
{
    $settings = telegram_settings($pdo);
    if (!telegram_is_configured($settings)) {
        return ['ok' => false, 'username' => '', 'error' => 'not_configured'];
    }
    $response = telegram_api_request($settings['token'], 'getMe');
    if (!$response['ok']) {
        return ['ok' => false, 'username' => '', 'error' => $response['error']];
    }
    $username = (string) ($response['result']['username'] ?? '');
    if ($username !== '') {
        set_app_setting($pdo, 'telegram_bot_username', $username, $actorUserId);
    }

    return ['ok' => true, 'username' => $username, 'error' => ''];
}

function telegram_send_message(array $settings, string $chatId, string $text, ?array $replyMarkup = null): bool
{
    if ($chatId === '') {
        return false;
    }
    $params = [
        'chat_id' => $chatId,
        'text' => $text,
        'disable_web_page_preview' => true,
    ];
    if ($replyMarkup !== null) {
        $params['reply_markup'] = $replyMarkup;
    }
    $response = telegram_api_request((string) $settings['token'], 'sendMessage', $params);

    return $response['ok'];
}

/** One prominent destination for alerts that can be resolved in the app. */
function telegram_action_keyboard(array $settings, string $path, ?string $label = null): ?array
{
    $baseUrl = rtrim(trim((string) ($settings['base_url'] ?? '')), '/');
    if ($baseUrl === '' || !str_starts_with($path, '/')) {
        return null;
    }

    return ['inline_keyboard' => [[[
        'text' => $label !== null && $label !== '' ? $label : '↗ ' . t('common.open'),
        'url' => $baseUrl . $path,
    ]]]];
}

function telegram_edit_message(array $settings, string $chatId, int $messageId, string $text, array $replyMarkup): bool
{
    if ($chatId === '' || $messageId <= 0) {
        return false;
    }
    $response = telegram_api_request((string) $settings['token'], 'editMessageText', [
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'text' => $text,
        'disable_web_page_preview' => true,
        'reply_markup' => $replyMarkup,
    ]);
    // Two very fast taps can legitimately converge on the same rendered state.
    return $response['ok'] || str_contains(strtolower((string) $response['error']), 'message is not modified');
}

function telegram_answer_callback(array $settings, string $callbackId, string $text = ''): void
{
    if ($callbackId === '') {
        return;
    }
    $params = ['callback_query_id' => $callbackId];
    if ($text !== '') {
        $params['text'] = $text;
    }
    telegram_api_request((string) $settings['token'], 'answerCallbackQuery', $params);
}

/**
 * Send a one-off test message and return the raw API result so the caller can
 * surface the exact Telegram error.
 *
 * @return array{ok:bool,result:mixed,error:string}
 */
function telegram_send_test(array $settings, string $chatId, string $text): array
{
    if ($chatId === '') {
        return ['ok' => false, 'result' => null, 'error' => 'no_chat_id'];
    }

    return telegram_api_request((string) $settings['token'], 'sendMessage', [
        'chat_id' => $chatId,
        'text' => $text,
        'disable_web_page_preview' => true,
    ]);
}

function telegram_deep_link(array $settings, string $code): string
{
    $username = (string) ($settings['username'] ?? '');
    if ($username === '' || $code === '') {
        return '';
    }

    return 'https://t.me/' . $username . '?start=' . rawurlencode($code);
}

/** Create (or reuse) a one-time link code for a user and return it. */
function telegram_generate_link_code(PDO $pdo, int $userId): string
{
    $code = 'fc' . bin2hex(random_bytes(6));
    db_execute(
        $pdo,
        'UPDATE users SET telegram_link_code = :code, updated_at = :updated_at WHERE id = :id',
        [':code' => $code, ':updated_at' => now_iso(), ':id' => $userId]
    );

    return $code;
}

function telegram_unlink_user(PDO $pdo, int $userId): void
{
    db_execute(
        $pdo,
        'UPDATE users
         SET telegram_chat_id = NULL, telegram_link_code = NULL,
             telegram_reminders_enabled = 0, telegram_motivation_enabled = 0,
             updated_at = :updated_at
         WHERE id = :id',
        [':updated_at' => now_iso(), ':id' => $userId]
    );
}

function telegram_update_user_prefs(PDO $pdo, int $userId, array $input): void
{
    db_execute(
        $pdo,
        'UPDATE users
         SET telegram_reminders_enabled = :reminders,
             telegram_motivation_enabled = :motivation,
             telegram_reminder_time = :reminder_time,
             telegram_quiet_start = :quiet_start,
             telegram_quiet_end = :quiet_end,
             telegram_weekends_off = :weekends_off,
             telegram_tz = :tz,
             telegram_notify_duel = :notify_duel,
             telegram_notify_streak = :notify_streak,
             telegram_notify_social = :notify_social,
             updated_at = :updated_at
         WHERE id = :id',
        [
            ':reminders' => !empty($input['telegram_reminders_enabled']) ? 1 : 0,
            ':motivation' => !empty($input['telegram_motivation_enabled']) ? 1 : 0,
            ':reminder_time' => telegram_normalize_time((string) ($input['telegram_reminder_time'] ?? '20:00')),
            ':quiet_start' => telegram_normalize_optional_time((string) ($input['telegram_quiet_start'] ?? '')),
            ':quiet_end' => telegram_normalize_optional_time((string) ($input['telegram_quiet_end'] ?? '')),
            ':weekends_off' => !empty($input['telegram_weekends_off']) ? 1 : 0,
            ':tz' => telegram_normalize_tz((string) ($input['telegram_tz'] ?? '')),
            ':notify_duel' => !empty($input['telegram_notify_duel']) ? 1 : 0,
            ':notify_streak' => !empty($input['telegram_notify_streak']) ? 1 : 0,
            ':notify_social' => !empty($input['telegram_notify_social']) ? 1 : 0,
            ':updated_at' => now_iso(),
            ':id' => $userId,
        ]
    );
}

/** Toggle a preference selected from Telegram's inline notification controls. */
function telegram_toggle_user_pref(PDO $pdo, int $userId, string $preference): bool
{
    $columns = [
        'reminders' => 'telegram_reminders_enabled',
        'motivation' => 'telegram_motivation_enabled',
        'duel' => 'telegram_notify_duel',
        'streak' => 'telegram_notify_streak',
        'social' => 'telegram_notify_social',
        'weekends' => 'telegram_weekends_off',
    ];
    $column = $columns[$preference] ?? null;
    if ($column === null) {
        return false;
    }
    $user = db_fetch_one($pdo, 'SELECT ' . $column . ' AS enabled FROM users WHERE id = :id AND active = 1', [':id' => $userId]);
    if ($user === null) {
        return false;
    }
    $enabled = (int) ($user['enabled'] ?? 0) !== 1;
    db_execute(
        $pdo,
        'UPDATE users SET ' . $column . ' = :enabled, updated_at = :now WHERE id = :id',
        [':enabled' => $enabled ? 1 : 0, ':now' => now_iso(), ':id' => $userId]
    );

    return true;
}

function telegram_set_reminder_time(PDO $pdo, int $userId, string $value): bool
{
    $time = telegram_normalize_optional_time($value);
    if ($time === '') {
        return false;
    }
    db_execute(
        $pdo,
        'UPDATE users SET telegram_reminder_time = :time, updated_at = :now WHERE id = :id',
        [':time' => $time, ':now' => now_iso(), ':id' => $userId]
    );

    return true;
}

function telegram_preferences_text(array $user): string
{
    $status = static fn(bool $enabled): string => $enabled ? '✅ ' . t('telegram.pref_on') : '○ ' . t('telegram.pref_off');
    $quietStart = telegram_normalize_optional_time((string) ($user['telegram_quiet_start'] ?? ''));
    $quietEnd = telegram_normalize_optional_time((string) ($user['telegram_quiet_end'] ?? ''));
    $quiet = $quietStart !== '' && $quietEnd !== '' ? $quietStart . '–' . $quietEnd : t('telegram.pref_none');
    $timezone = trim((string) ($user['telegram_tz'] ?? '')) ?: t('telegram.pref_none');

    return implode("\n", [
        t('telegram.preferences_title'),
        t('telegram.preferences_hint'),
        '',
        $status((int) ($user['telegram_reminders_enabled'] ?? 0) === 1) . ' · ' . t('telegram.pref_reminders'),
        $status((int) ($user['telegram_motivation_enabled'] ?? 0) === 1) . ' · ' . t('telegram.pref_motivation'),
        $status((int) ($user['telegram_notify_duel'] ?? 1) === 1) . ' · ' . t('telegram.pref_duel'),
        $status((int) ($user['telegram_notify_streak'] ?? 1) === 1) . ' · ' . t('telegram.pref_streak'),
        $status((int) ($user['telegram_notify_social'] ?? 1) === 1) . ' · ' . t('telegram.pref_social'),
        $status((int) ($user['telegram_weekends_off'] ?? 0) === 1) . ' · ' . t('telegram.pref_weekends'),
        '🕘 ' . t('settings.telegram_time') . ': ' . telegram_normalize_time((string) ($user['telegram_reminder_time'] ?? '20:00')),
        '🌙 ' . t('telegram.pref_quiet') . ': ' . $quiet,
        '🌍 ' . t('settings.telegram_tz') . ': ' . $timezone,
    ]);
}

function telegram_preferences_keyboard(array $settings, array $user): array
{
    $toggle = static function (string $label, string $column, string $callback, int $default = 0) use ($user): array {
        $enabled = (int) ($user[$column] ?? $default) === 1;

        return ['text' => ($enabled ? '✅ ' : '○ ') . $label, 'callback_data' => 'tgpref:' . $callback];
    };
    $rows = [
        [
            $toggle(t('telegram.pref_reminders'), 'telegram_reminders_enabled', 'reminders'),
            $toggle(t('telegram.pref_motivation'), 'telegram_motivation_enabled', 'motivation'),
        ],
        [
            $toggle(t('telegram.pref_duel'), 'telegram_notify_duel', 'duel', 1),
            $toggle(t('telegram.pref_streak'), 'telegram_notify_streak', 'streak', 1),
        ],
        [$toggle(t('telegram.pref_social'), 'telegram_notify_social', 'social', 1)],
        [$toggle(t('telegram.pref_weekends'), 'telegram_weekends_off', 'weekends')],
        [
            ['text' => '−30 min', 'callback_data' => 'tgtime:-30'],
            ['text' => telegram_normalize_time((string) ($user['telegram_reminder_time'] ?? '20:00')), 'callback_data' => 'tgtime:0'],
            ['text' => '+30 min', 'callback_data' => 'tgtime:30'],
        ],
    ];
    $baseUrl = rtrim((string) ($settings['base_url'] ?? ''), '/');
    if ($baseUrl !== '') {
        $rows[] = [['text' => '⚙️ ' . t('common.open'), 'url' => $baseUrl . '/?page=settings&view=integrations#telegram']];
    }

    return ['inline_keyboard' => $rows];
}

function telegram_send_preferences(PDO $pdo, array $settings, string $chatId, array $user): void
{
    $fresh = db_fetch_one($pdo, 'SELECT * FROM users WHERE id = :id AND active = 1', [':id' => (int) ($user['id'] ?? 0)]) ?? $user;
    telegram_with_user_locale($fresh, static function () use ($settings, $chatId, $fresh): void {
        telegram_send_message($settings, $chatId, telegram_preferences_text($fresh), telegram_preferences_keyboard($settings, $fresh));
    });
}

/**
 * "Now" (H:i and Y-m-d) in the user's own timezone (#15). Falls back to the
 * server timezone when the user has not set one, so existing users are
 * unaffected.
 *
 * @return array{hm:string,date:string,dow:int}
 */
function telegram_user_now(array $user): array
{
    $tz = trim((string) ($user['telegram_tz'] ?? ''));
    try {
        $zone = $tz !== '' ? new DateTimeZone($tz) : null;
        $now = $zone !== null ? new DateTimeImmutable('now', $zone) : new DateTimeImmutable('now');
    } catch (Throwable) {
        $now = new DateTimeImmutable('now');
    }

    return ['hm' => $now->format('H:i'), 'date' => $now->format('Y-m-d'), 'dow' => (int) $now->format('N')];
}

/** Accept only a real IANA timezone; anything else falls back to server time. */
function telegram_normalize_tz(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    return in_array($value, timezone_identifiers_list(), true) ? $value : '';
}

/** Like telegram_normalize_time() but allows an empty value (feature off). */
function telegram_normalize_optional_time(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $value, $matches) !== 1) {
        return '';
    }

    return sprintf('%02d:%02d', (int) $matches[1], (int) $matches[2]);
}

/**
 * Whether HH:MM falls inside a quiet-hours window. Handles overnight windows
 * (e.g. 22:00–07:00). An empty start or end disables quiet hours.
 */
function telegram_in_quiet_hours(string $start, string $end, string $nowHm): bool
{
    $start = telegram_normalize_optional_time($start);
    $end = telegram_normalize_optional_time($end);
    if ($start === '' || $end === '' || $start === $end) {
        return false;
    }
    if ($start < $end) {
        // Same-day window, e.g. 01:00–06:00.
        return $nowHm >= $start && $nowHm < $end;
    }

    // Overnight window, e.g. 22:00–07:00.
    return $nowHm >= $start || $nowHm < $end;
}

function telegram_normalize_time(string $value): string
{
    $value = trim($value);
    if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $value, $matches) !== 1) {
        return '20:00';
    }

    return sprintf('%02d:%02d', (int) $matches[1], (int) $matches[2]);
}

/**
 * Poll Telegram for incoming messages and link /start <code> senders to their
 * user. Throttled so it runs at most once per TELEGRAM_POLL_MIN_INTERVAL.
 */
function telegram_poll_updates(PDO $pdo, array $settings): void
{
    $last = strtotime((string) ($settings['last_poll_at'] ?? ''));
    if ($last !== false && (time() - $last) < TELEGRAM_POLL_MIN_INTERVAL) {
        return;
    }
    set_app_setting_silent($pdo, 'telegram_last_poll_at', now_iso());

    $response = telegram_api_request((string) $settings['token'], 'getUpdates', [
        'offset' => (int) $settings['offset'],
        'timeout' => 0,
        'allowed_updates' => ['message', 'callback_query'],
    ]);
    if (!$response['ok'] || !is_array($response['result'])) {
        return;
    }

    $maxUpdateId = (int) $settings['offset'];
    foreach ($response['result'] as $update) {
        if (!is_array($update)) {
            continue;
        }
        $updateId = (int) ($update['update_id'] ?? 0);
        if ($updateId >= $maxUpdateId) {
            $maxUpdateId = $updateId + 1;
        }
        $callback = is_array($update['callback_query'] ?? null) ? $update['callback_query'] : null;
        if ($callback !== null) {
            telegram_handle_callback($pdo, $settings, $callback);
            continue;
        }
        $message = is_array($update['message'] ?? null) ? $update['message'] : [];
        $text = trim((string) ($message['text'] ?? ''));
        $chatId = (string) ($message['chat']['id'] ?? '');
        if ($text === '' || $chatId === '') {
            continue;
        }
        telegram_handle_incoming($pdo, $settings, $chatId, $text);
    }

    if ($maxUpdateId !== (int) $settings['offset']) {
        set_app_setting_silent($pdo, 'telegram_update_offset', (string) $maxUpdateId);
    }
}

function telegram_handle_incoming(PDO $pdo, array $settings, string $chatId, string $text): void
{
    // Expect "/start <code>" (Telegram sends this when a user opens the deep link).
    if (!preg_match('/^\/start(?:@\w+)?\s+(\S+)/i', $text, $matches)) {
        $user = db_fetch_one($pdo, 'SELECT * FROM users WHERE telegram_chat_id = :chat_id AND active = 1', [':chat_id' => $chatId]);
        if ($user === null) {
            telegram_send_message($settings, $chatId, t('telegram.not_linked'));
            return;
        }
        $parts = preg_split('/\s+/', trim($text), 2) ?: [];
        $command = strtolower(preg_replace('/@\w+$/', '', ltrim((string) ($parts[0] ?? ''), '/')) ?? '');
        if (in_array($command, ['notifications', 'notification', 'avisos', 'settings'], true)) {
            telegram_send_preferences($pdo, $settings, $chatId, $user);
            return;
        }
        if ($command === 'time') {
            $value = trim((string) ($parts[1] ?? ''));
            if (!telegram_set_reminder_time($pdo, (int) $user['id'], $value)) {
                telegram_with_user_locale($user, static fn() => telegram_send_message($settings, $chatId, t('telegram.time_invalid')));
                return;
            }
            telegram_send_preferences($pdo, $settings, $chatId, $user);
            return;
        }
        telegram_with_user_locale($user, static fn() => telegram_send_message($settings, $chatId, t('telegram.help')));
        return;
    }
    $code = $matches[1];
    $user = db_fetch_one($pdo, 'SELECT * FROM users WHERE telegram_link_code = :code', [':code' => $code]);
    if ($user === null) {
        telegram_send_message($settings, $chatId, t('telegram.msg_invalid_link'));
        return;
    }
    db_execute(
        $pdo,
        'UPDATE users
         SET telegram_chat_id = :chat_id, telegram_link_code = NULL,
             telegram_reminders_enabled = 1, telegram_motivation_enabled = 1,
             updated_at = :updated_at
         WHERE id = :id',
        [':chat_id' => $chatId, ':updated_at' => now_iso(), ':id' => (int) $user['id']]
    );
    telegram_with_user_locale($user, static function () use ($settings, $chatId): void {
        telegram_send_message($settings, $chatId, t('telegram.msg_linked'));
    });
}

function telegram_handle_callback(PDO $pdo, array $settings, array $callback): void
{
    $callbackId = trim((string) ($callback['id'] ?? ''));
    $message = is_array($callback['message'] ?? null) ? $callback['message'] : [];
    $chatId = (string) ($message['chat']['id'] ?? '');
    $messageId = (int) ($message['message_id'] ?? 0);
    $data = trim((string) ($callback['data'] ?? ''));
    $user = $chatId !== ''
        ? db_fetch_one($pdo, 'SELECT * FROM users WHERE telegram_chat_id = :chat_id AND active = 1', [':chat_id' => $chatId])
        : null;
    if ($user === null) {
        telegram_answer_callback($settings, $callbackId, t('telegram.not_linked'));
        return;
    }

    $changed = false;
    if (str_starts_with($data, 'tgpref:')) {
        $changed = telegram_toggle_user_pref($pdo, (int) $user['id'], substr($data, 7));
    } elseif (str_starts_with($data, 'tgtime:')) {
        $delta = filter_var(substr($data, 7), FILTER_VALIDATE_INT);
        if ($delta !== false && in_array((int) $delta, [-30, 0, 30], true)) {
            $current = DateTimeImmutable::createFromFormat('!H:i', telegram_normalize_time((string) ($user['telegram_reminder_time'] ?? '20:00')));
            if ($current !== false) {
                $next = $current->modify(sprintf('%+d minutes', (int) $delta));
                $changed = telegram_set_reminder_time($pdo, (int) $user['id'], $next->format('H:i'));
            }
        }
    }

    if (!$changed) {
        telegram_answer_callback($settings, $callbackId);
        return;
    }
    $fresh = db_fetch_one($pdo, 'SELECT * FROM users WHERE id = :id AND active = 1', [':id' => (int) $user['id']]) ?? $user;
    telegram_with_user_locale($fresh, static function () use ($settings, $chatId, $messageId, $callbackId, $fresh): void {
        telegram_edit_message($settings, $chatId, $messageId, telegram_preferences_text($fresh), telegram_preferences_keyboard($settings, $fresh));
        telegram_answer_callback($settings, $callbackId, t('telegram.preference_saved'));
    });
}

/** Run t() in a user's locale, then restore. */
function telegram_with_user_locale(array $user, callable $callback): mixed
{
    $previous = function_exists('current_locale') ? current_locale() : 'en';
    if (function_exists('set_current_locale')) {
        set_current_locale((string) ($user['locale'] ?? $previous));
    }
    try {
        return $callback();
    } finally {
        if (function_exists('set_current_locale')) {
            set_current_locale($previous);
        }
    }
}

function telegram_user_logged_today(PDO $pdo, int $userId, string $date): bool
{
    $row = db_fetch_one(
        $pdo,
        'SELECT id FROM daily_logs
         WHERE user_id = :user_id AND log_date = :date
           AND (COALESCE(steps, 0) > 0 OR workout_done = 1 OR COALESCE(distance_km, 0) > 0
                OR weight IS NOT NULL OR COALESCE(TRIM(notes), \'\') <> \'\')',
        [':user_id' => $userId, ':date' => $date]
    );

    return $row !== null;
}

function telegram_pick_quote(PDO $pdo, array $user): string
{
    $own = trim((string) ($user['motivation_quote'] ?? ''));
    if ($own !== '') {
        return $own;
    }
    // Prefer a quote in the user's language (or one tagged for all languages).
    if (function_exists('random_motivation_quote_from_db')) {
        return random_motivation_quote_from_db($pdo, (string) ($user['locale'] ?? 'en'));
    }
    $quotes = function_exists('list_motivational_quotes') ? list_motivational_quotes($pdo, true) : [];
    if ($quotes === []) {
        return '';
    }
    $pick = $quotes[array_rand($quotes)];

    return trim((string) ($pick['quote_text'] ?? ''));
}

/**
 * Send due reminders/motivation. A user gets at most one reminder and one
 * motivation message per day, at or after their configured time, and only when
 * they still have not logged the day.
 */
function telegram_run_reminders(PDO $pdo, array $settings): void
{
    $today = date('Y-m-d');
    $nowHm = date('H:i');
    $sends = 0;

    $users = db_fetch_all(
        $pdo,
        "SELECT * FROM users
         WHERE active = 1
           AND telegram_chat_id IS NOT NULL AND TRIM(telegram_chat_id) <> ''
           AND (telegram_reminders_enabled = 1 OR telegram_motivation_enabled = 1)"
    );

    foreach ($users as $user) {
        if ($sends >= TELEGRAM_MAX_SENDS_PER_TICK) {
            break;
        }
        // Everything below is evaluated in the user's own timezone (#15), so a
        // 20:00 reminder means 20:00 where they live, not on the server.
        $userNow = telegram_user_now($user);
        $nowHm = $userNow['hm'];
        $today = $userNow['date'];

        $reminderTime = telegram_normalize_time((string) ($user['telegram_reminder_time'] ?? '20:00'));
        if ($nowHm < $reminderTime) {
            continue;
        }
        // Respect quiet hours and the optional weekends-off preference.
        if (telegram_in_quiet_hours((string) ($user['telegram_quiet_start'] ?? ''), (string) ($user['telegram_quiet_end'] ?? ''), $nowHm)) {
            continue;
        }
        if ((int) ($user['telegram_weekends_off'] ?? 0) === 1 && in_array($userNow['dow'], [6, 7], true)) {
            continue;
        }
        $chatId = (string) $user['telegram_chat_id'];
        $userId = (int) $user['id'];
        $loggedToday = telegram_user_logged_today($pdo, $userId, $today);

        if ((int) ($user['telegram_reminders_enabled'] ?? 0) === 1
            && (string) ($user['telegram_last_reminded_on'] ?? '') !== $today
            && !$loggedToday
        ) {
            $text = telegram_with_user_locale($user, static fn(): string => t('telegram.msg_reminder', [
                'name' => (string) ($user['display_name'] ?? ''),
            ]));
            // Only consume the daily slot on a successful send so a transient
            // failure retries on the next tick instead of skipping the day.
            if (telegram_send_message($settings, $chatId, $text, telegram_action_keyboard($settings, '/?page=entries&mode=data'))) {
                $sends++;
                db_execute($pdo, 'UPDATE users SET telegram_last_reminded_on = :d WHERE id = :id', [':d' => $today, ':id' => $userId]);
            }
        }

        if ((int) ($user['telegram_motivation_enabled'] ?? 0) === 1
            && (string) ($user['telegram_last_motivation_on'] ?? '') !== $today
        ) {
            $quote = telegram_pick_quote($pdo, $user);
            if ($quote !== '') {
                $text = telegram_with_user_locale($user, static fn(): string => t('telegram.msg_motivation', ['quote' => $quote]));
                if (telegram_send_message($settings, $chatId, $text, telegram_action_keyboard($settings, '/?page=dashboard'))) {
                    $sends++;
                    db_execute($pdo, 'UPDATE users SET telegram_last_motivation_on = :d WHERE id = :id', [':d' => $today, ':id' => $userId]);
                }
            }
        }

        // --- Event-driven reminders (#15). Each is opt-in and, like the daily
        // reminder, only fires when the underlying data actually warrants it. ---
        foreach (telegram_event_reminders($pdo, $user, $today, $loggedToday) as $event) {
            if ($sends >= TELEGRAM_MAX_SENDS_PER_TICK) {
                break;
            }
            // De-duplicate per event per day using the existing outbox key idea:
            // a sent marker in app_settings keyed by user+event+date.
            $marker = 'tg_evt:' . $userId . ':' . $event['key'] . ':' . $today;
            if (trim((string) (app_setting($pdo, $marker, '') ?? '')) !== '') {
                continue;
            }
            $text = telegram_with_user_locale($user, static fn(): string => $event['text']);
            $eventPath = str_starts_with((string) ($event['key'] ?? ''), 'duel_')
                ? '/?page=duels'
                : '/?page=entries&mode=data';
            if (telegram_send_message($settings, $chatId, $text, telegram_action_keyboard($settings, $eventPath))) {
                $sends++;
                set_app_setting_silent($pdo, $marker, '1', $userId);
            }
        }
    }
}

/**
 * Build the event reminders due for a user right now. Returns nothing unless
 * the data says the nudge is warranted, which is what keeps this from becoming
 * spam.
 *
 * @return array<int,array{key:string,text:string}>
 */
function telegram_event_reminders(PDO $pdo, array $user, string $today, bool $loggedToday): array
{
    $userId = (int) ($user['id'] ?? 0);
    $events = [];

    // 1. A duel of yours ends tomorrow (or today) and is still active.
    if ((int) ($user['telegram_notify_duel'] ?? 0) === 1 && function_exists('duels_for_user')) {
        try {
            $tomorrow = (new DateTimeImmutable($today))->modify('+1 day')->format('Y-m-d');
            $row = db_fetch_one(
                $pdo,
                'SELECT COUNT(*) AS c FROM user_duels
                 WHERE status = \'active\' AND (challenger_id = :u OR opponent_id = :u)
                   AND end_date IS NOT NULL AND end_date <= :tomorrow',
                [':u' => $userId, ':tomorrow' => $tomorrow]
            );
            if ((int) ($row['c'] ?? 0) > 0) {
                $events[] = ['key' => 'duel_ending', 'text' => t('telegram.msg_duel_ending')];
            }
        } catch (Throwable) {
            // Duels schema not present yet — nothing to nudge about.
        }
    }

    // 2. Your streak is alive but you have not logged today, so it is at risk.
    if ((int) ($user['telegram_notify_streak'] ?? 0) === 1 && !$loggedToday && function_exists('quests_active_streak')) {
        try {
            $streak = quests_active_streak($pdo, $userId);
            if ($streak >= 3) {
                $events[] = ['key' => 'streak_risk', 'text' => t('telegram.msg_streak_risk', ['n' => $streak])];
            }
        } catch (Throwable) {
            // Quests schema not present yet.
        }
    }

    return $events;
}

/**
 * Outbox for event-driven push messages (friend/duel/competition activity).
 * Social events fire during a web request; rather than block on a Telegram API
 * call we enqueue here and let whichever process owns Telegram I/O drain it.
 * Self-contained (lazy schema).
 */
function telegram_outbox_ensure_schema(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS telegram_outbox (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            kind TEXT NOT NULL DEFAULT "social",
            text TEXT NOT NULL,
            created_at TEXT NOT NULL,
            sent_at TEXT
        )'
    );
    ensure_column($pdo, 'telegram_outbox', 'kind', 'TEXT NOT NULL DEFAULT "social"');
}

/** Queue a categorized push message for a user, only if they opted into it. */
function telegram_enqueue(PDO $pdo, int $userId, string $text, string $kind = 'social'): bool
{
    $text = trim($text);
    if ($userId <= 0 || $text === '') {
        return false;
    }
    if (!telegram_is_enabled(telegram_settings($pdo))) {
        return false;
    }
    $user = db_fetch_one(
        $pdo,
        'SELECT telegram_chat_id, telegram_notify_duel, telegram_notify_social
         FROM users WHERE id = :id AND active = 1',
        [':id' => $userId]
    );
    if ($user === null || trim((string) ($user['telegram_chat_id'] ?? '')) === '') {
        return false;
    }
    $optedIn = str_starts_with($kind, 'duel_')
        ? (int) ($user['telegram_notify_duel'] ?? 1) === 1
        : (int) ($user['telegram_notify_social'] ?? 1) === 1;
    if (!$optedIn) {
        return false;
    }
    telegram_outbox_ensure_schema($pdo);
    db_execute(
        $pdo,
        'INSERT INTO telegram_outbox (user_id, kind, text, created_at) VALUES (:u, :k, :t, :c)',
        [':u' => $userId, ':k' => $kind, ':t' => $text, ':c' => now_iso()]
    );

    return true;
}

/** Send queued outbox messages (run by whichever process owns Telegram I/O). */
function telegram_drain_outbox(PDO $pdo, array $settings): void
{
    telegram_outbox_ensure_schema($pdo);
    $rows = db_fetch_all($pdo, 'SELECT * FROM telegram_outbox WHERE sent_at IS NULL ORDER BY id ASC LIMIT 25');
    $sent = 0;
    foreach ($rows as $row) {
        if ($sent >= TELEGRAM_MAX_SENDS_PER_TICK) {
            break;
        }
        $outboxId = (int) $row['id'];
        $recipient = db_fetch_one($pdo, 'SELECT * FROM users WHERE id = :id AND active = 1', [':id' => (int) $row['user_id']]);
        $chatId = $recipient !== null ? trim((string) ($recipient['telegram_chat_id'] ?? '')) : '';
        if ($chatId === '') {
            // Recipient unlinked since queueing: drop the message.
            db_execute($pdo, 'UPDATE telegram_outbox SET sent_at = :now WHERE id = :id', [':now' => now_iso(), ':id' => $outboxId]);
            continue;
        }
        $kind = (string) ($row['kind'] ?? 'social');
        $optedIn = str_starts_with($kind, 'duel_')
            ? (int) ($recipient['telegram_notify_duel'] ?? 1) === 1
            : (int) ($recipient['telegram_notify_social'] ?? 1) === 1;
        if (!$optedIn) {
            db_execute($pdo, 'UPDATE telegram_outbox SET sent_at = :now WHERE id = :id', [':now' => now_iso(), ':id' => $outboxId]);
            continue;
        }
        $recipientNow = telegram_user_now($recipient);
        if (telegram_in_quiet_hours(
            (string) ($recipient['telegram_quiet_start'] ?? ''),
            (string) ($recipient['telegram_quiet_end'] ?? ''),
            $recipientNow['hm']
        )) {
            continue;
        }
        if ((int) ($recipient['telegram_weekends_off'] ?? 0) === 1 && in_array((int) ($recipientNow['dow'] ?? 0), [6, 7], true)) {
            continue;
        }
        $destination = str_starts_with($kind, 'duel_')
            ? '/?page=duels'
            : (str_starts_with($kind, 'competition_') ? '/?page=competitions' : '/?page=social');
        if (telegram_send_message($settings, $chatId, (string) $row['text'], telegram_action_keyboard($settings, $destination))) {
            db_execute($pdo, 'UPDATE telegram_outbox SET sent_at = :now WHERE id = :id', [':now' => now_iso(), ':id' => $outboxId]);
            $sent++;
        }
        // On failure leave sent_at NULL so it retries on the next drain.
    }
}

/**
 * Scheduler tick (mirrors run_system_backup_scheduler / notion_run_scheduler).
 * Polls for links and sends due reminders. Failures are swallowed so a Telegram
 * outage never breaks page rendering.
 */
function telegram_run_scheduler(PDO $pdo, array $config): void
{
    try {
        $settings = telegram_settings($pdo);
        if (!telegram_is_enabled($settings)) {
            return;
        }
        // A live standalone worker owns Telegram I/O. Expired leases recover
        // automatically, unlike the legacy permanent external flag.
        if (integration_runtime_lease_is_active($pdo, 'telegram')) {
            return;
        }
        telegram_poll_updates($pdo, $settings);
        telegram_run_reminders($pdo, $settings);
        telegram_drain_outbox($pdo, $settings);
    } catch (Throwable $exception) {
        error_log('telegram_run_scheduler: ' . $exception->getMessage());
    }
}
