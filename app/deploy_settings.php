<?php

declare(strict_types=1);

// Read/write helpers for the Docker Compose published-port env files
// (`.env` for local dev, `.env.live` for the live_manager.py flow). This
// module never shells out to Docker and never restarts any container — it
// only edits the plain KEY=VALUE file that `docker compose` resolves on the
// next manual `up -d`. See bin/live_manager.py for the authoritative parser
// this mirrors (`parse_env_file`) and writer (`write_live_env`).

const DEPLOY_PORT_DEFAULT_HTTP = 8080;
const DEPLOY_PORT_DEFAULT_HTTPS = 8443;

/**
 * Parse a simple `KEY=VALUE` env file with the same tolerant semantics as
 * bin/live_manager.py's parse_env_file(): blank lines and `#` comments are
 * skipped, an optional leading `export ` is stripped, and values may be
 * wrapped in matching single or double quotes.
 *
 * @return array<string, string>
 */
function deploy_parse_env_file(string $path): array
{
    $data = [];
    if (!is_file($path)) {
        return $data;
    }

    $contents = @file_get_contents($path);
    if ($contents === false) {
        return $data;
    }

    foreach (preg_split('/\r\n|\r|\n/', $contents) ?: [] as $rawLine) {
        $line = trim($rawLine);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (str_starts_with($line, 'export ')) {
            $line = trim(substr($line, 7));
        }
        if (!str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        $len = strlen($value);
        if ($len >= 2 && (
            ($value[0] === '"' && $value[$len - 1] === '"')
            || ($value[0] === "'" && $value[$len - 1] === "'")
        )) {
            $value = substr($value, 1, -1);
        }
        if ($key !== '') {
            $data[$key] = $value;
        }
    }

    return $data;
}

/**
 * Write HTTP_PORT/HTTPS_PORT into an env file, preserving every other
 * existing key and reasonable ordering (HTTP_PORT, HTTPS_PORT first, then
 * the remaining keys in their original file order, then any brand-new
 * keys — none expected here — appended last).
 *
 * Returns true on success, false if the file could not be written (caller
 * is expected to have already checked deploy_env_file_is_writable()).
 */
function deploy_write_ports_to_env_file(string $path, int $httpPort, int $httpsPort): bool
{
    $existing = deploy_parse_env_file($path);
    $originalOrder = [];
    if (is_file($path)) {
        $contents = @file_get_contents($path);
        foreach (preg_split('/\r\n|\r|\n/', $contents !== false ? $contents : '') ?: [] as $rawLine) {
            $line = trim($rawLine);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (str_starts_with($line, 'export ')) {
                $line = trim(substr($line, 7));
            }
            if (!str_contains($line, '=')) {
                continue;
            }
            $key = trim(explode('=', $line, 2)[0]);
            if ($key !== '' && !in_array($key, $originalOrder, true)) {
                $originalOrder[] = $key;
            }
        }
    }

    $merged = $existing;
    $merged['HTTP_PORT'] = (string) $httpPort;
    $merged['HTTPS_PORT'] = (string) $httpsPort;

    $orderedKeys = ['HTTP_PORT', 'HTTPS_PORT'];
    foreach ($originalOrder as $key) {
        if (!in_array($key, $orderedKeys, true)) {
            $orderedKeys[] = $key;
        }
    }
    foreach (array_keys($merged) as $key) {
        if (!in_array($key, $orderedKeys, true)) {
            $orderedKeys[] = $key;
        }
    }

    $lines = [];
    foreach ($orderedKeys as $key) {
        $lines[] = $key . '=' . (string) ($merged[$key] ?? '');
    }
    $lines[] = '';

    $dir = dirname($path);
    if (!is_dir($dir)) {
        return false;
    }

    return @file_put_contents($path, implode("\n", $lines)) !== false;
}

/**
 * True when the given path exists and is writable, or when it does not
 * exist yet but its parent directory is writable (so a first save can
 * create it).
 */
function deploy_env_file_is_writable(string $path): bool
{
    if (is_file($path)) {
        return is_writable($path);
    }

    return is_dir(dirname($path)) && is_writable(dirname($path));
}

/**
 * Resolve the two candidate mount paths for the live/local env files inside
 * the app container (or the project root when running outside Docker, e.g.
 * `php -S` for local dev/tests).
 *
 * @return array{live: string, local: string}
 */
function deploy_port_env_paths(): array
{
    $config = is_array($GLOBALS['config'] ?? null) ? (array) $GLOBALS['config'] : [];
    $override = is_array($config['deploy_port_env_paths'] ?? null) ? (array) $config['deploy_port_env_paths'] : null;
    if ($override !== null && isset($override['live'], $override['local'])) {
        return [
            'live' => (string) $override['live'],
            'local' => (string) $override['local'],
        ];
    }

    $basePath = dirname(__DIR__);

    return [
        'live' => env_value('DEPLOY_ENV_LIVE_PATH', $basePath . '/.env.live') ?? $basePath . '/.env.live',
        'local' => env_value('DEPLOY_ENV_LOCAL_PATH', $basePath . '/.env') ?? $basePath . '/.env',
    ];
}

/**
 * Build the full status snapshot the admin panel needs: which file is
 * authoritative (.env.live wins if present on disk), its current ports,
 * whether it is writable, and the exact command to run to apply a change.
 *
 * @return array{
 *   mode: string,
 *   path: string,
 *   exists: bool,
 *   writable: bool,
 *   http_port: int,
 *   https_port: int,
 *   apply_command: string,
 * }
 */
function deploy_port_settings_status(): array
{
    $paths = deploy_port_env_paths();

    // An empty (or comment-only) .env.live is treated as "not provisioned
    // yet" rather than authoritative — this matters because the Docker bind
    // mount requires the file to pre-exist on the host (otherwise Docker
    // creates a directory in its place), so an empty placeholder file may
    // exist even though `bin/live_manager.py provision` was never run.
    $liveValues = deploy_parse_env_file($paths['live']);
    $liveProvisioned = is_file($paths['live']) && $liveValues !== [];

    $activePath = $paths['local'];
    $mode = 'local';
    if ($liveProvisioned) {
        $activePath = $paths['live'];
        $mode = 'live';
    }

    $exists = is_file($activePath) && deploy_parse_env_file($activePath) !== [];
    $values = $mode === 'live' ? $liveValues : deploy_parse_env_file($activePath);
    $httpPort = $exists && isset($values['HTTP_PORT']) && ctype_digit($values['HTTP_PORT'])
        ? (int) $values['HTTP_PORT']
        : DEPLOY_PORT_DEFAULT_HTTP;
    $httpsPort = $exists && isset($values['HTTPS_PORT']) && ctype_digit($values['HTTPS_PORT'])
        ? (int) $values['HTTPS_PORT']
        : DEPLOY_PORT_DEFAULT_HTTPS;

    return [
        'mode' => $mode,
        'path' => $activePath,
        'exists' => $exists,
        'writable' => deploy_env_file_is_writable($activePath),
        'http_port' => $httpPort,
        'https_port' => $httpsPort,
        'apply_command' => $mode === 'live' ? 'python bin/live_manager.py deploy' : 'docker compose up -d',
    ];
}

/**
 * Validate a submitted HTTP/HTTPS port pair. Returns an error message key
 * (suitable for t()) or '' when the pair is valid.
 */
function deploy_port_settings_validate(int $httpPort, int $httpsPort): string
{
    if ($httpPort < 1 || $httpPort > 65535 || $httpsPort < 1 || $httpsPort > 65535) {
        return 'admin.deploy_ports_error_range';
    }
    if ($httpPort === $httpsPort) {
        return 'admin.deploy_ports_error_same';
    }

    return '';
}

/**
 * Persist a new HTTP/HTTPS port pair to the currently-authoritative env
 * file (creating `.env` on first save if neither file exists yet — never
 * `.env.live`, which is only meant to be generated deliberately via
 * `bin/live_manager.py provision`). Throws on validation or write failure
 * with a message safe to show the admin.
 */
function deploy_port_settings_save(int $httpPort, int $httpsPort): array
{
    $error = deploy_port_settings_validate($httpPort, $httpsPort);
    if ($error !== '') {
        throw new RuntimeException(t($error));
    }

    $status = deploy_port_settings_status();
    if (!$status['writable']) {
        throw new RuntimeException(t('admin.deploy_ports_error_not_writable', ['path' => $status['path']]));
    }

    if (!deploy_write_ports_to_env_file($status['path'], $httpPort, $httpsPort)) {
        throw new RuntimeException(t('admin.deploy_ports_error_write_failed', ['path' => $status['path']]));
    }

    return deploy_port_settings_status();
}
