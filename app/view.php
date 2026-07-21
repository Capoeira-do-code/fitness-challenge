<?php

declare(strict_types=1);

function render_view(string $view, array $params = []): never
{
    extract($params, EXTR_SKIP);

    ob_start();
    require __DIR__ . '/views/' . $view . '.php';
    $content = ob_get_clean();

    ob_start();
    require __DIR__ . '/views/layout.php';
    $html = (string) ob_get_clean();

    header('Content-Type: text/html; charset=UTF-8');
    header('Vary: Accept-Encoding');
    $acceptEncoding = strtolower((string) ($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''));
    $zlibOutputEnabled = filter_var(ini_get('zlib.output_compression'), FILTER_VALIDATE_BOOLEAN);
    if (!$zlibOutputEnabled && function_exists('gzencode') && strlen($html) >= 1024 && str_contains($acceptEncoding, 'gzip')) {
        $compressed = gzencode($html, 6);
        if (is_string($compressed)) {
            $html = $compressed;
            header('Content-Encoding: gzip');
        }
    }
    header('Content-Length: ' . strlen($html));
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'HEAD') {
        echo $html;
    }
    exit;
}
