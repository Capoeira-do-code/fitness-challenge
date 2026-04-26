<?php

declare(strict_types=1);

function render_view(string $view, array $params = []): never
{
    extract($params, EXTR_SKIP);

    ob_start();
    require __DIR__ . '/views/' . $view . '.php';
    $content = ob_get_clean();

    require __DIR__ . '/views/layout.php';
    exit;
}
