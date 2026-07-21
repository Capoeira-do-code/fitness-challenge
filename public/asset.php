<?php

declare(strict_types=1);

/**
 * Lightweight compressed asset delivery for local servers exposed through an
 * HTTP tunnel. PHP's development server does not gzip static files and some
 * HTTP/2 relays cannot infer the end of dynamic responses without a length.
 */

function asset_fail(int $status, string $message): never
{
    $body = $message . "\n";
    http_response_code($status);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Content-Length: ' . strlen($body));
    header('Cache-Control: no-store');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
        echo $body;
    }
    exit;
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    asset_fail(405, 'Method not allowed.');
}

$relative = str_replace('\\', '/', trim((string) ($_GET['file'] ?? ''), '/'));
if ($relative === '' || str_contains($relative, '..') || str_contains($relative, "\0")) {
    asset_fail(400, 'Invalid asset path.');
}

$assetRoot = realpath(__DIR__ . '/assets');
$filePath = realpath(__DIR__ . '/assets/' . $relative);
if ($assetRoot === false || $filePath === false || !is_file($filePath)) {
    asset_fail(404, 'Asset not found.');
}
$assetPrefix = rtrim(str_replace('\\', '/', $assetRoot), '/') . '/';
$normalizedFile = str_replace('\\', '/', $filePath);
if (!str_starts_with($normalizedFile, $assetPrefix)) {
    asset_fail(404, 'Asset not found.');
}

$extension = strtolower((string) pathinfo($filePath, PATHINFO_EXTENSION));
$mimeTypes = [
    'css' => 'text/css; charset=UTF-8',
    'js' => 'application/javascript; charset=UTF-8',
    'json' => 'application/json; charset=UTF-8',
    'svg' => 'image/svg+xml',
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'webp' => 'image/webp',
    'gif' => 'image/gif',
    'ico' => 'image/x-icon',
    'woff' => 'font/woff',
    'woff2' => 'font/woff2',
    'ttf' => 'font/ttf',
    'map' => 'application/json; charset=UTF-8',
];
if (!isset($mimeTypes[$extension])) {
    asset_fail(415, 'Unsupported asset type.');
}

$mtime = (int) (@filemtime($filePath) ?: time());
$size = (int) (@filesize($filePath) ?: 0);
$compressible = in_array($extension, ['css', 'js', 'json', 'svg', 'map'], true);
$acceptEncoding = strtolower((string) ($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''));
$useGzip = $compressible && function_exists('gzencode') && str_contains($acceptEncoding, 'gzip');
$etag = '"' . sha1($relative . '|' . $mtime . '|' . $size . '|' . ($useGzip ? 'gzip' : 'identity')) . '"';

header('Content-Type: ' . $mimeTypes[$extension]);
header('Cache-Control: public, max-age=' . (trim((string) ($_GET['v'] ?? '')) !== '' ? '31536000, immutable' : '3600'));
header('ETag: ' . $etag);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
header('X-Content-Type-Options: nosniff');
header('Vary: Accept-Encoding');

$ifNoneMatch = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
if ($ifNoneMatch === $etag || $ifNoneMatch === '*') {
    http_response_code(304);
    exit;
}

$body = file_get_contents($filePath);
if ($body === false) {
    asset_fail(500, 'Asset could not be read.');
}
if ($useGzip) {
    $compressed = gzencode($body, 6);
    if (is_string($compressed)) {
        $body = $compressed;
        header('Content-Encoding: gzip');
    }
}

header('Content-Length: ' . strlen($body));
if ($method !== 'HEAD') {
    echo $body;
}
exit;
