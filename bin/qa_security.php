#!/usr/bin/env php
<?php

declare(strict_types=1);

putenv('DB_PATH=:memory:');
putenv('SEED_PASSWORD=qa-security-password');
putenv('REQUEST_SCHEDULERS_ENABLED=0');
putenv('APP_ALLOWED_HOSTS');
putenv('SECURITY_AUTO_BLOCK');
putenv('SECURITY_LOG_RETENTION_DAYS');

require dirname(__DIR__) . '/app/bootstrap.php';

$failures = [];
$check = static function (bool $condition, string $label) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . '  ' . $label . PHP_EOL;
    if (!$condition) {
        $failures[] = $label;
    }
};

$serverSnapshot = $_SERVER;
$testConfig = $config;
$testConfig['security_trusted_proxies'] = '127.0.0.1,::1,172.16.0.0/12';

$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_X_REAL_IP'] = '203.0.113.44';
$check(security_client_ip_address($testConfig) === '203.0.113.44', 'a trusted local proxy can provide the client IP');

$_SERVER['REMOTE_ADDR'] = '198.51.100.20';
$_SERVER['HTTP_X_REAL_IP'] = '203.0.113.99';
$check(security_client_ip_address($testConfig) === '198.51.100.20', 'forwarded IP headers from an untrusted peer are ignored');

$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
unset($_SERVER['HTTP_X_REAL_IP']);
$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.8';
$check(security_client_ip_address($testConfig) === '203.0.113.8', 'a trusted single-hop forwarded chain resolves the public client');

$probePaths = ['/info', '/php-info.php', '/_profiler/phpinfo', '/_environment', '/.env', '/vendor/phpunit/'];
foreach ($probePaths as $probePath) {
    $classification = security_classify_request($probePath, 'GET');
    $check($classification['suspicious'] && $classification['risk_score'] >= 70, $probePath . ' is classified as a vulnerability probe');
}
$check(!security_classify_request('/workouts', 'GET')['suspicious'], 'a normal application route is not classified as a scanner');
$check(security_classify_request('/', 'TRACE')['suspicious'], 'TRACE is rejected as a forbidden method');

$check(security_parse_host_header('fitness.example.com:8443') === [
    'host' => 'fitness.example.com',
    'port' => 8443,
    'authority' => 'fitness.example.com:8443',
], 'a valid host and port are normalized');
$check(security_parse_host_header("fitness.example.com\r\nX-Evil: yes") === null, 'header injection in Host is rejected');
$check(security_parse_host_header('fitness.example.com,evil.example') === null, 'multiple Host values are rejected');

$allowedHosts = security_parse_allowed_hosts("fitness.example.com\n*.team.example.com", true);
$check(security_host_matches_allowed('fitness.example.com', $allowedHosts), 'an exact allowed domain matches');
$check(security_host_matches_allowed('app.team.example.com', $allowedHosts), 'an allowed wildcard subdomain matches');
$check(!security_host_matches_allowed('team.example.com', $allowedHosts), 'a wildcard does not silently include its root domain');
$check(!security_host_matches_allowed('evil.example', $allowedHosts), 'an unrelated domain is rejected');

$_SERVER['REQUEST_URI'] = '/_environment?token=super-secret&password=hidden';
$pathOnly = security_request_path();
$check($pathOnly === '/_environment', 'request logging strips the entire query string');
$logId = security_insert_access_log($pdo, [
    'request_id' => bin2hex(random_bytes(12)),
    'ip_address' => '203.0.113.8',
    'network_ip' => '127.0.0.1',
    'method' => 'GET',
    'host' => 'fitness.example.com',
    'path' => $pathOnly,
    'route' => '',
    'event_type' => 'scanner_probe',
    'risk_score' => 90,
    'user_agent' => 'QA scanner',
    'created_at' => date('Y-m-d H:i:s'),
]);
$storedLog = db_fetch_one($pdo, 'SELECT * FROM security_access_logs WHERE id = :id', [':id' => $logId]);
$storedJson = json_encode($storedLog, JSON_THROW_ON_ERROR);
$check($logId > 0 && $storedLog !== null, 'an access attempt is persisted');
$check(!str_contains($storedJson, 'super-secret') && !str_contains($storedJson, 'password=hidden'), 'secrets from query parameters are not persisted');
$check((string) ($storedLog['ip_address'] ?? '') === '203.0.113.8', 'the resolved client IP is stored');
$check((string) ($storedLog['network_ip'] ?? '') === '127.0.0.1', 'the immediate network peer is stored separately');

security_block_ip($pdo, '203.0.113.8', 'QA repeated scan', 60);
$check(security_is_ip_blocked($pdo, '203.0.113.8'), 'a public scanner IP can be temporarily blocked');
$check(!security_is_ip_blocked($pdo, '127.0.0.1'), 'loopback is never auto-blocked');
$check(security_unblock_ip($pdo, '203.0.113.8'), 'an administrator can remove an IP block');
$check(!security_is_ip_blocked($pdo, '203.0.113.8'), 'the removed block no longer applies');

set_app_setting_silent($pdo, 'security_allowed_hosts', "fitness.example.com\n*.team.example.com");
set_app_setting_silent($pdo, 'security_auto_block', '1');
set_app_setting_silent($pdo, 'security_log_retention_days', '45');
$runtimeSettings = security_runtime_settings($pdo, $testConfig);
$check($runtimeSettings['host_enforced'], 'a configured domain list enables strict Host enforcement');
$check($runtimeSettings['auto_block'], 'automatic scanner blocking is enabled');
$check((int) $runtimeSettings['retention_days'] === 45, 'the configured log retention is applied');

$_SERVER = $serverSnapshot;

if ($failures !== []) {
    fwrite(STDERR, PHP_EOL . count($failures) . ' security QA check(s) failed.' . PHP_EOL);
    exit(1);
}

echo PHP_EOL . 'Security QA passed.' . PHP_EOL;
