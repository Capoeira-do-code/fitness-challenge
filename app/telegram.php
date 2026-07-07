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
    ];
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

function telegram_update_settings(PDO $pdo, array $input, int $actorUserId): void
{
    $token = trim((string) ($input['telegram_bot_token'] ?? ''));
    if ($token === '') {
        $token = trim((string) (app_setting($pdo, 'telegram_bot_token', '') ?? ''));
    }
    set_app_setting($pdo, 'telegram_enabled', !empty($input['telegram_enabled']) ? '1' : '0', $actorUserId);
    set_app_setting($pdo, 'telegram_external_bot', !empty($input['telegram_external_bot']) ? '1' : '0', $actorUserId);
    set_app_setting($pdo, 'telegram_bot_token', $token, $actorUserId);
    set_app_setting($pdo, 'telegram_bot_username', ltrim(trim((string) ($input['telegram_bot_username'] ?? '')), '@'), $actorUserId);
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

            return ['ok' => false, 'result' => null, 'error' => $error !== '' ? $error : 'curl_failed'];
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
        return ['ok' => false, 'result' => null, 'error' => (string) ($data['description'] ?? 'telegram_error')];
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

function telegram_send_message(array $settings, string $chatId, string $text): bool
{
    if ($chatId === '') {
        return false;
    }
    $response = telegram_api_request((string) $settings['token'], 'sendMessage', [
        'chat_id' => $chatId,
        'text' => $text,
        'disable_web_page_preview' => true,
    ]);

    return $response['ok'];
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
             updated_at = :updated_at
         WHERE id = :id',
        [
            ':reminders' => !empty($input['telegram_reminders_enabled']) ? 1 : 0,
            ':motivation' => !empty($input['telegram_motivation_enabled']) ? 1 : 0,
            ':reminder_time' => telegram_normalize_time((string) ($input['telegram_reminder_time'] ?? '20:00')),
            ':updated_at' => now_iso(),
            ':id' => $userId,
        ]
    );
}

function telegram_normalize_time(string $value): string
{
    $value = trim($value);

    return preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $value) === 1 ? $value : '20:00';
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
        'allowed_updates' => ['message'],
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
    if (!preg_match('/^\/start\s+(\S+)/', $text, $matches)) {
        return;
    }
    $code = $matches[1];
    $user = db_fetch_one($pdo, 'SELECT * FROM users WHERE telegram_link_code = :code', [':code' => $code]);
    if ($user === null) {
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
        $reminderTime = telegram_normalize_time((string) ($user['telegram_reminder_time'] ?? '20:00'));
        if ($nowHm < $reminderTime) {
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
            if (telegram_send_message($settings, $chatId, $text)) {
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
                if (telegram_send_message($settings, $chatId, $text)) {
                    $sends++;
                    db_execute($pdo, 'UPDATE users SET telegram_last_motivation_on = :d WHERE id = :id', [':d' => $today, ':id' => $userId]);
                }
            }
        }
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
        // When the standalone Python bot (bin/telegram_bot.py) runs, it owns all
        // Telegram I/O — the PHP app must not poll or send, to avoid double
        // reminders and getUpdates offset conflicts.
        if (!empty($settings['external_bot'])) {
            return;
        }
        telegram_poll_updates($pdo, $settings);
        telegram_run_reminders($pdo, $settings);
    } catch (Throwable $exception) {
        error_log('telegram_run_scheduler: ' . $exception->getMessage());
    }
}
