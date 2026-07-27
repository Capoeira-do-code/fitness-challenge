<?php

declare(strict_types=1);

/**
 * Router for PHP's development server.
 *
 * Existing public assets are served normally. Every other request, including
 * nonexistent PHP diagnostics commonly used by scanners, passes through the
 * secured front controller so it receives the correct status and is logged.
 */

$requestPath = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
$requestPath = rawurldecode(str_replace('\\', '/', $requestPath));
$publicRoot = realpath(__DIR__);
$candidate = $publicRoot !== false ? realpath($publicRoot . '/' . ltrim($requestPath, '/')) : false;
$candidateIsPublicFile = is_string($candidate)
    && is_file($candidate)
    && is_string($publicRoot)
    && str_starts_with(str_replace('\\', '/', $candidate), rtrim(str_replace('\\', '/', $publicRoot), '/') . '/');

if ($candidateIsPublicFile) {
    $normalizedPath = '/' . ltrim(str_replace('\\', '/', substr($candidate, strlen($publicRoot))), '/');
    if (str_starts_with($normalizedPath, '/uploads/')) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        header('Cache-Control: no-store');
        echo "Forbidden\n";

        return true;
    }

    $basename = strtolower(basename($candidate));
    $extension = strtolower((string) pathinfo($candidate, PATHINFO_EXTENSION));
    if ($extension !== 'php' || $basename === 'asset.php') {
        return false;
    }
}

require __DIR__ . '/index.php';

return true;
