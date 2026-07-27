<?php

declare(strict_types=1);

/**
 * Request security and access logging.
 *
 * Deliberately never stores query strings, request bodies, cookies,
 * authorization headers or session identifiers.
 */

function security_clean_log_value(mixed $value, int $maxLength = 255): string
{
    $clean = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', trim((string) $value));
    if (!is_string($clean)) {
        $clean = '';
    }
    $clean = preg_replace('/\s+/u', ' ', $clean) ?? $clean;

    return substr($clean, 0, max(1, $maxLength));
}

function security_direct_ip_address(): string
{
    $remote = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));

    return filter_var($remote, FILTER_VALIDATE_IP) !== false ? $remote : 'unknown';
}

function security_ip_matches_cidr(string $ipAddress, string $cidr): bool
{
    $ipAddress = trim($ipAddress);
    $cidr = trim($cidr);
    if (filter_var($ipAddress, FILTER_VALIDATE_IP) === false || $cidr === '') {
        return false;
    }

    if (!str_contains($cidr, '/')) {
        return filter_var($cidr, FILTER_VALIDATE_IP) !== false && $ipAddress === $cidr;
    }

    [$network, $prefixRaw] = array_pad(explode('/', $cidr, 2), 2, '');
    if (filter_var($network, FILTER_VALIDATE_IP) === false || !ctype_digit($prefixRaw)) {
        return false;
    }

    $ipBytes = @inet_pton($ipAddress);
    $networkBytes = @inet_pton($network);
    if (!is_string($ipBytes) || !is_string($networkBytes) || strlen($ipBytes) !== strlen($networkBytes)) {
        return false;
    }

    $prefix = (int) $prefixRaw;
    $maxBits = strlen($ipBytes) * 8;
    if ($prefix < 0 || $prefix > $maxBits) {
        return false;
    }

    $wholeBytes = intdiv($prefix, 8);
    $remainingBits = $prefix % 8;
    if ($wholeBytes > 0 && substr($ipBytes, 0, $wholeBytes) !== substr($networkBytes, 0, $wholeBytes)) {
        return false;
    }
    if ($remainingBits === 0) {
        return true;
    }

    $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

    return (ord($ipBytes[$wholeBytes]) & $mask) === (ord($networkBytes[$wholeBytes]) & $mask);
}

/** @return array<int,string> */
function security_trusted_proxy_ranges(array $config): array
{
    $raw = trim((string) ($config['security_trusted_proxies'] ?? '127.0.0.1,::1'));

    return array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', $raw) ?: [])));
}

function security_request_from_trusted_proxy(array $config): bool
{
    // CLI QA utilities may emulate proxy headers without a network peer.
    if (PHP_SAPI === 'cli' && security_direct_ip_address() === 'unknown') {
        return true;
    }

    $directIp = security_direct_ip_address();
    foreach (security_trusted_proxy_ranges($config) as $range) {
        if (security_ip_matches_cidr($directIp, $range)) {
            return true;
        }
    }

    return false;
}

function security_valid_forwarded_ip(mixed $value): string
{
    $candidate = trim((string) $value, " \t\n\r\0\x0B\"");

    return filter_var($candidate, FILTER_VALIDATE_IP) !== false ? $candidate : '';
}

function security_client_ip_address(array $config): string
{
    $directIp = security_direct_ip_address();
    if (!security_request_from_trusted_proxy($config)) {
        return $directIp;
    }

    foreach (['HTTP_X_REAL_IP', 'HTTP_CF_CONNECTING_IP'] as $headerName) {
        $candidate = security_valid_forwarded_ip($_SERVER[$headerName] ?? '');
        if ($candidate !== '') {
            return $candidate;
        }
    }

    $forwardedFor = trim((string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
    if ($forwardedFor !== '') {
        $chain = array_slice(array_map('trim', explode(',', $forwardedFor)), -20);
        $chain[] = $directIp;
        for ($index = count($chain) - 1; $index >= 0; $index--) {
            $candidate = security_valid_forwarded_ip($chain[$index]);
            if ($candidate === '') {
                continue;
            }
            $trusted = false;
            foreach (security_trusted_proxy_ranges($config) as $range) {
                if (security_ip_matches_cidr($candidate, $range)) {
                    $trusted = true;
                    break;
                }
            }
            if (!$trusted) {
                return $candidate;
            }
        }
    }

    return $directIp;
}

function security_ip_is_loopback(string $ipAddress): bool
{
    return security_ip_matches_cidr($ipAddress, '127.0.0.0/8')
        || security_ip_matches_cidr($ipAddress, '::1/128');
}

function security_first_forwarded_header_value(mixed $value): string
{
    return trim(explode(',', (string) $value, 2)[0], " \t\n\r\0\x0B\"");
}

function security_request_is_https(array $config): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if (!security_request_from_trusted_proxy($config)) {
        return false;
    }

    $forwardedProto = strtolower(security_first_forwarded_header_value($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    if ($forwardedProto === '') {
        $forwarded = security_first_forwarded_header_value($_SERVER['HTTP_FORWARDED'] ?? '');
        if (preg_match('/(?:^|;)\s*proto=(?:"([^"]+)"|([^;]+))/i', $forwarded, $matches) === 1) {
            $forwardedProto = strtolower(trim((string) (($matches[1] ?? '') !== '' ? $matches[1] : ($matches[2] ?? ''))));
        }
    }

    return $forwardedProto === 'https';
}

/** @return array{host:string,port:?int,authority:string}|null */
function security_parse_host_header(mixed $value): ?array
{
    $authority = trim((string) $value);
    if ($authority === '' || strlen($authority) > 280 || preg_match('/[\x00-\x20\x7F,\/\\\\@]/', $authority) === 1) {
        return null;
    }

    $parts = parse_url('http://' . $authority);
    if (!is_array($parts) || isset($parts['user']) || isset($parts['pass']) || isset($parts['path']) || isset($parts['query']) || isset($parts['fragment'])) {
        return null;
    }
    $host = strtolower(trim((string) ($parts['host'] ?? '')));
    $port = isset($parts['port']) ? (int) $parts['port'] : null;
    $validHost = filter_var($host, FILTER_VALIDATE_IP) !== false
        || $host === 'localhost'
        || preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i', $host) === 1;
    if (!$validHost || ($port !== null && ($port < 1 || $port > 65535))) {
        return null;
    }

    $hostForAuthority = str_contains($host, ':') ? '[' . $host . ']' : $host;

    return [
        'host' => $host,
        'port' => $port,
        'authority' => $hostForAuthority . ($port !== null ? ':' . $port : ''),
    ];
}

/** @return array{host:string,port:?int,authority:string}|null */
function security_request_host(array $config): ?array
{
    $hostHeader = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if (security_request_from_trusted_proxy($config)) {
        $forwardedHost = security_first_forwarded_header_value($_SERVER['HTTP_X_FORWARDED_HOST'] ?? '');
        if ($forwardedHost === '') {
            $forwarded = security_first_forwarded_header_value($_SERVER['HTTP_FORWARDED'] ?? '');
            if (preg_match('/(?:^|;)\s*host=(?:"([^"]+)"|([^;]+))/i', $forwarded, $matches) === 1) {
                $forwardedHost = trim((string) (($matches[1] ?? '') !== '' ? $matches[1] : ($matches[2] ?? '')));
            }
        }
        if ($forwardedHost !== '') {
            $hostHeader = $forwardedHost;
        }
    }

    return security_parse_host_header($hostHeader);
}

/** @return array<int,string> */
function security_parse_allowed_hosts(string $raw, bool $strict = false): array
{
    $entries = [];
    foreach (preg_split('/[\s,]+/', trim($raw)) ?: [] as $entry) {
        $entry = strtolower(trim($entry));
        if ($entry === '') {
            continue;
        }
        if (str_contains($entry, '://')) {
            $entry = strtolower((string) (parse_url($entry, PHP_URL_HOST) ?? ''));
        }
        $entry = trim($entry, '[]');
        $wildcard = str_starts_with($entry, '*.');
        $host = $wildcard ? substr($entry, 2) : $entry;
        $valid = filter_var($host, FILTER_VALIDATE_IP) !== false
            || $host === 'localhost'
            || preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i', $host) === 1;
        if (!$valid || ($wildcard && filter_var($host, FILTER_VALIDATE_IP) !== false)) {
            if ($strict) {
                $invalidHost = security_clean_log_value($entry, 120);
                throw new InvalidArgumentException(
                    function_exists('t')
                        ? t('security.invalid_host_entry', ['host' => $invalidHost])
                        : ('Invalid allowed host: ' . $invalidHost)
                );
            }
            continue;
        }
        $entries[] = ($wildcard ? '*.' : '') . $host;
    }

    return array_values(array_unique($entries));
}

function security_host_matches_allowed(string $host, array $allowedHosts): bool
{
    $host = strtolower(trim($host, '[]'));
    foreach ($allowedHosts as $allowed) {
        $allowed = strtolower(trim((string) $allowed));
        if ($allowed === $host) {
            return true;
        }
        if (str_starts_with($allowed, '*.') && $host !== substr($allowed, 2) && str_ends_with($host, substr($allowed, 1))) {
            return true;
        }
    }

    return false;
}

function security_database_setting(PDO $pdo, string $key, ?string $default = null): ?string
{
    try {
        $statement = $pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key = :key');
        $statement->execute([':key' => $key]);
        $value = $statement->fetchColumn();

        return $value !== false ? (string) $value : $default;
    } catch (Throwable) {
        return $default;
    }
}

/** @return array{allowed_hosts:array<int,string>,allowed_hosts_raw:string,allowed_hosts_source:string,host_enforced:bool,auto_block:bool,retention_days:int,rate_limit_per_minute:int,scan_threshold:int,block_minutes:int} */
function security_runtime_settings(PDO $pdo, array $config): array
{
    $environmentHosts = trim((string) ($config['security_allowed_hosts'] ?? ''));
    $databaseHosts = trim((string) (security_database_setting($pdo, 'security_allowed_hosts', '') ?? ''));
    $allowedHostsRaw = $environmentHosts !== '' ? $environmentHosts : $databaseHosts;
    $autoBlockOverride = $config['security_auto_block'] ?? null;
    $autoBlockValue = $autoBlockOverride !== null
        ? (string) $autoBlockOverride
        : (string) (security_database_setting($pdo, 'security_auto_block', '1') ?? '1');
    $retentionOverride = (int) ($config['security_log_retention_days'] ?? 0);
    $retentionDays = $retentionOverride > 0
        ? $retentionOverride
        : (int) (security_database_setting($pdo, 'security_log_retention_days', '90') ?? 90);

    return [
        'allowed_hosts' => security_parse_allowed_hosts($allowedHostsRaw),
        'allowed_hosts_raw' => $allowedHostsRaw,
        'allowed_hosts_source' => $environmentHosts !== '' ? 'environment' : 'admin',
        'host_enforced' => security_parse_allowed_hosts($allowedHostsRaw) !== [],
        'auto_block' => in_array(strtolower(trim($autoBlockValue)), ['1', 'true', 'yes', 'on'], true),
        'retention_days' => max(7, min(365, $retentionDays)),
        'rate_limit_per_minute' => max(60, (int) ($config['security_rate_limit_per_minute'] ?? 240)),
        'scan_threshold' => max(3, (int) ($config['security_scan_threshold'] ?? 5)),
        'block_minutes' => max(5, (int) ($config['security_block_minutes'] ?? 60)),
    ];
}

function security_request_path(): string
{
    $path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
    $decoded = rawurldecode($path);
    $decoded = str_replace('\\', '/', $decoded);
    $decoded = preg_replace('#/+#', '/', $decoded) ?? $decoded;

    return substr($decoded !== '' ? $decoded : '/', 0, 500);
}

/** @return array{event_type:string,risk_score:int,suspicious:bool} */
function security_classify_request(string $path, string $method): array
{
    $method = strtoupper($method);
    if (in_array($method, ['TRACE', 'TRACK', 'CONNECT'], true)) {
        return ['event_type' => 'forbidden_method', 'risk_score' => 90, 'suspicious' => true];
    }

    $suspiciousPatterns = [
        '#(?:^|/)(?:\.env(?:\..*)?|\.git|\.svn|\.hg|composer\.(?:json|lock)|package-lock\.json|yarn\.lock)(?:/|$)#i',
        '#(?:^|/)(?:phpinfo|php-info|php_info|phpversion|_phpinfo|old_phpinfo|server-info|server-status)(?:\.php)?(?:/|$)#i',
        '#(?:^|/)(?:_profiler|_environment|_debugbar|debug|telescope|actuator|vendor/phpunit|wp-admin|wp-login)(?:/|$)#i',
        '#(?:^|/)(?:config|backup|database|dump)(?:\.(?:php|ini|conf|sql|sqlite|db|bak|old|zip|tar|gz))(?:/|$)#i',
        '#(?:^|/)(?:info)(?:/|$)#i',
    ];
    foreach ($suspiciousPatterns as $pattern) {
        if (preg_match($pattern, $path) === 1) {
            return ['event_type' => 'scanner_probe', 'risk_score' => 90, 'suspicious' => true];
        }
    }

    return ['event_type' => 'request', 'risk_score' => 0, 'suspicious' => false];
}

/** @param array<string,mixed> $request */
function security_insert_access_log(PDO $pdo, array $request): int
{
    try {
        $statement = $pdo->prepare(
            'INSERT INTO security_access_logs
                (request_id, ip_address, network_ip, user_id, method, host, path, route, status_code, event_type, risk_score, user_agent, duration_ms, created_at, completed_at)
             VALUES
                (:request_id, :ip_address, :network_ip, NULL, :method, :host, :path, :route, 0, :event_type, :risk_score, :user_agent, 0, :created_at, NULL)'
        );
        $statement->execute([
            ':request_id' => (string) $request['request_id'],
            ':ip_address' => (string) $request['ip_address'],
            ':network_ip' => (string) $request['network_ip'],
            ':method' => (string) $request['method'],
            ':host' => (string) $request['host'],
            ':path' => (string) $request['path'],
            ':route' => (string) $request['route'],
            ':event_type' => (string) $request['event_type'],
            ':risk_score' => (int) $request['risk_score'],
            ':user_agent' => (string) $request['user_agent'],
            ':created_at' => (string) $request['created_at'],
        ]);

        return (int) $pdo->lastInsertId();
    } catch (Throwable $exception) {
        error_log('[security] Access log insert failed: ' . $exception->getMessage());

        return 0;
    }
}

function security_mark_access_log(PDO $pdo, int $logId, string $eventType, int $riskScore): void
{
    if ($logId <= 0) {
        return;
    }
    try {
        $statement = $pdo->prepare(
            'UPDATE security_access_logs
             SET event_type = :event_type, risk_score = MAX(risk_score, :risk_score)
             WHERE id = :id'
        );
        $statement->execute([
            ':event_type' => security_clean_log_value($eventType, 40),
            ':risk_score' => max(0, min(100, $riskScore)),
            ':id' => $logId,
        ]);
    } catch (Throwable) {
    }
}

function security_complete_access_log(PDO $pdo, int $logId, float $startedAt): void
{
    if ($logId <= 0) {
        return;
    }
    $statusCode = http_response_code();
    $statusCode = is_int($statusCode) && $statusCode >= 100 ? $statusCode : 200;
    $lastError = error_get_last();
    if (is_array($lastError) && in_array((int) ($lastError['type'] ?? 0), [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        $statusCode = max(500, $statusCode);
    }
    $userId = isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    $durationMs = max(0, round((microtime(true) - $startedAt) * 1000, 2));

    try {
        $statement = $pdo->prepare(
            'UPDATE security_access_logs
             SET user_id = :user_id, status_code = :status_code, duration_ms = :duration_ms, completed_at = :completed_at
             WHERE id = :id'
        );
        $statement->execute([
            ':user_id' => $userId,
            ':status_code' => $statusCode,
            ':duration_ms' => $durationMs,
            ':completed_at' => date('Y-m-d H:i:s'),
            ':id' => $logId,
        ]);
    } catch (Throwable $exception) {
        error_log('[security] Access log completion failed: ' . $exception->getMessage());
    }
}

function security_is_ip_blocked(PDO $pdo, string $ipAddress): bool
{
    if ($ipAddress === 'unknown' || security_ip_is_loopback($ipAddress)) {
        return false;
    }
    try {
        $statement = $pdo->prepare('SELECT 1 FROM security_ip_blocks WHERE ip_address = :ip AND blocked_until > :now LIMIT 1');
        $statement->execute([':ip' => $ipAddress, ':now' => date('Y-m-d H:i:s')]);

        return $statement->fetchColumn() !== false;
    } catch (Throwable) {
        return false;
    }
}

function security_block_ip(PDO $pdo, string $ipAddress, string $reason, int $minutes): void
{
    if (filter_var($ipAddress, FILTER_VALIDATE_IP) === false || security_ip_is_loopback($ipAddress)) {
        return;
    }
    $now = new DateTimeImmutable('now');
    $blockedUntil = $now->modify('+' . max(5, $minutes) . ' minutes')->format('Y-m-d H:i:s');
    try {
        $statement = $pdo->prepare(
            'INSERT INTO security_ip_blocks (ip_address, reason, blocked_until, hit_count, created_at, updated_at)
             VALUES (:ip, :reason, :blocked_until, 1, :now, :now)
             ON CONFLICT(ip_address) DO UPDATE SET
                reason = excluded.reason,
                blocked_until = excluded.blocked_until,
                hit_count = security_ip_blocks.hit_count + 1,
                updated_at = excluded.updated_at'
        );
        $statement->execute([
            ':ip' => $ipAddress,
            ':reason' => security_clean_log_value($reason, 160),
            ':blocked_until' => $blockedUntil,
            ':now' => $now->format('Y-m-d H:i:s'),
        ]);
    } catch (Throwable $exception) {
        error_log('[security] IP block failed: ' . $exception->getMessage());
    }
}

function security_recent_event_count(PDO $pdo, string $ipAddress, int $minutes, bool $suspiciousOnly): int
{
    $cutoff = (new DateTimeImmutable('-' . max(1, $minutes) . ' minutes'))->format('Y-m-d H:i:s');
    $eventClause = $suspiciousOnly ? ' AND risk_score >= 70' : '';
    try {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM security_access_logs
             WHERE ip_address = :ip AND created_at >= :cutoff' . $eventClause
        );
        $statement->execute([':ip' => $ipAddress, ':cutoff' => $cutoff]);

        return (int) $statement->fetchColumn();
    } catch (Throwable) {
        return 0;
    }
}

function security_prune_access_data(PDO $pdo, int $retentionDays): void
{
    $cutoff = (new DateTimeImmutable('-' . max(7, min(365, $retentionDays)) . ' days'))->format('Y-m-d H:i:s');
    try {
        $pdo->prepare('DELETE FROM security_access_logs WHERE created_at < :cutoff')->execute([':cutoff' => $cutoff]);
        $pdo->prepare('DELETE FROM security_ip_blocks WHERE blocked_until <= :now')->execute([':now' => date('Y-m-d H:i:s')]);
    } catch (Throwable) {
    }
}

function security_reject_request(int $statusCode, string $message = 'Not Found'): never
{
    http_response_code($statusCode);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    if ($statusCode === 429) {
        header('Retry-After: 60');
    }
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'HEAD') {
        echo $message . "\n";
    }
    exit;
}

function security_boot_request(PDO $pdo, array $config): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }

    $startedAt = microtime(true);
    $method = strtoupper(security_clean_log_value($_SERVER['REQUEST_METHOD'] ?? 'GET', 12));
    $path = security_request_path();
    $host = security_request_host($config);
    $classification = security_classify_request($path, $method);
    if ($host === null) {
        $classification = ['event_type' => 'invalid_host', 'risk_score' => 100, 'suspicious' => true];
    }
    $route = $_GET['page'] ?? '';
    $route = is_scalar($route) ? security_clean_log_value($route, 80) : '';
    $requestId = bin2hex(random_bytes(12));
    $ipAddress = security_client_ip_address($config);
    $settings = security_runtime_settings($pdo, $config);
    $logId = security_insert_access_log($pdo, [
        'request_id' => $requestId,
        'ip_address' => $ipAddress,
        'network_ip' => security_direct_ip_address(),
        'method' => $method,
        'host' => $host['authority'] ?? security_clean_log_value($_SERVER['HTTP_HOST'] ?? '', 280),
        'path' => $path,
        'route' => $route,
        'event_type' => $classification['event_type'],
        'risk_score' => $classification['risk_score'],
        'user_agent' => security_clean_log_value($_SERVER['HTTP_USER_AGENT'] ?? '', 300),
        'created_at' => date('Y-m-d H:i:s'),
    ]);
    $GLOBALS['security_access_log_id'] = $logId;
    $GLOBALS['security_request_id'] = $requestId;
    register_shutdown_function(static function () use ($pdo, $logId, $startedAt): void {
        security_complete_access_log($pdo, $logId, $startedAt);
    });

    header('X-Request-Id: ' . $requestId);

    if ($logId > 0 && $logId % 100 === 0) {
        security_prune_access_data($pdo, (int) $settings['retention_days']);
    }

    if (security_is_ip_blocked($pdo, $ipAddress)) {
        security_mark_access_log($pdo, $logId, 'blocked', 100);
        security_reject_request(429, 'Too Many Requests');
    }

    if ($host === null) {
        security_reject_request(400, 'Bad Request');
    }
    if ($settings['host_enforced'] && !security_host_matches_allowed($host['host'], $settings['allowed_hosts'])) {
        security_mark_access_log($pdo, $logId, 'host_rejected', 100);
        security_reject_request(421, 'Misdirected Request');
    }

    if ($classification['suspicious']) {
        $probeCount = security_recent_event_count($pdo, $ipAddress, 10, true);
        if ($settings['auto_block'] && !security_ip_is_loopback($ipAddress) && $probeCount >= $settings['scan_threshold']) {
            security_block_ip($pdo, $ipAddress, 'Repeated vulnerability scanning', $settings['block_minutes']);
            security_mark_access_log($pdo, $logId, 'scanner_blocked', 100);
            security_reject_request(429, 'Too Many Requests');
        }
        security_reject_request($method === 'TRACE' || $method === 'TRACK' || $method === 'CONNECT' ? 405 : 404, 'Not Found');
    }

    $requestCount = security_recent_event_count($pdo, $ipAddress, 1, false);
    if ($settings['auto_block']
        && !security_ip_is_loopback($ipAddress)
        && $requestCount > $settings['rate_limit_per_minute']
    ) {
        security_block_ip($pdo, $ipAddress, 'Application request rate exceeded', 10);
        security_mark_access_log($pdo, $logId, 'rate_limited', 80);
        security_reject_request(429, 'Too Many Requests');
    }
}

function security_mark_current_request(PDO $pdo, string $eventType, int $riskScore = 0): void
{
    security_mark_access_log($pdo, (int) ($GLOBALS['security_access_log_id'] ?? 0), $eventType, $riskScore);
}

function security_apply_response_headers(array $config, bool $authenticated = false): void
{
    header_remove('X-Powered-By');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('X-Permitted-Cross-Domain-Policies: none');
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    header(
        "Content-Security-Policy: default-src 'self'; "
        . "base-uri 'self'; object-src 'none'; frame-ancestors 'self'; form-action 'self'; "
        . "script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
        . "font-src 'self' data: https://fonts.gstatic.com; img-src 'self' data: blob: https:; "
        . "media-src 'self' blob: https:; frame-src https://www.youtube-nocookie.com https://player.vimeo.com; "
        . "connect-src 'self'; worker-src 'self' blob:; manifest-src 'self'"
    );
    if ($authenticated) {
        header('Cache-Control: no-store');
    }
    if (security_request_is_https($config)) {
        $host = security_request_host($config);
        if ($host !== null && !security_ip_is_loopback($host['host']) && $host['host'] !== 'localhost') {
            header('Strict-Transport-Security: max-age=31536000');
        }
    }
}

/** @return array<string,int> */
function security_access_summary(PDO $pdo): array
{
    $cutoff = (new DateTimeImmutable('-24 hours'))->format('Y-m-d H:i:s');
    try {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) AS total,
                    COUNT(DISTINCT ip_address) AS unique_ips,
                    SUM(CASE WHEN risk_score >= 70 THEN 1 ELSE 0 END) AS suspicious,
                    SUM(CASE WHEN status_code >= 400 THEN 1 ELSE 0 END) AS errors
             FROM security_access_logs WHERE created_at >= :cutoff'
        );
        $statement->execute([':cutoff' => $cutoff]);
        $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        $blocks = (int) $pdo->query(
            "SELECT COUNT(*) FROM security_ip_blocks WHERE blocked_until > datetime('now', 'localtime')"
        )->fetchColumn();

        return [
            'total' => (int) ($row['total'] ?? 0),
            'unique_ips' => (int) ($row['unique_ips'] ?? 0),
            'suspicious' => (int) ($row['suspicious'] ?? 0),
            'errors' => (int) ($row['errors'] ?? 0),
            'active_blocks' => $blocks,
        ];
    } catch (Throwable) {
        return ['total' => 0, 'unique_ips' => 0, 'suspicious' => 0, 'errors' => 0, 'active_blocks' => 0];
    }
}

/** @return array<int,array<string,mixed>> */
function security_recent_access_logs(PDO $pdo, int $limit = 200, string $ipFilter = ''): array
{
    $limit = max(1, min(500, $limit));
    $params = [];
    $where = '';
    $ipFilter = trim($ipFilter);
    if ($ipFilter !== '') {
        $where = 'WHERE logs.ip_address = :ip';
        $params[':ip'] = $ipFilter;
    }
    try {
        $statement = $pdo->prepare(
            'SELECT logs.*, users.display_name AS user_name
             FROM security_access_logs logs
             LEFT JOIN users ON users.id = logs.user_id
             ' . $where . '
             ORDER BY logs.id DESC LIMIT ' . $limit
        );
        $statement->execute($params);

        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable) {
        return [];
    }
}

/** @return array<int,array<string,mixed>> */
function security_active_ip_blocks(PDO $pdo): array
{
    try {
        return $pdo->query(
            "SELECT * FROM security_ip_blocks
             WHERE blocked_until > datetime('now', 'localtime')
             ORDER BY blocked_until DESC"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable) {
        return [];
    }
}

function security_unblock_ip(PDO $pdo, string $ipAddress): bool
{
    if (filter_var($ipAddress, FILTER_VALIDATE_IP) === false) {
        return false;
    }
    $statement = $pdo->prepare('DELETE FROM security_ip_blocks WHERE ip_address = :ip');
    $statement->execute([':ip' => $ipAddress]);

    return $statement->rowCount() > 0;
}
