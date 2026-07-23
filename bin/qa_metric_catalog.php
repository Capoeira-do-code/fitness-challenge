<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/metrics.php';
require_once __DIR__ . '/../app/workouts.php';

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

foreach ([
    '10000' => 10000,
    '10 000' => 10000,
    '10.000' => 10000,
    '12 345' => 12345,
] as $input => $expected) {
    $check(parse_localized_positive_integer($input) === $expected, 'Localized integer rejected: ' . $input);
}
foreach (['', '0', '-1', 'steps', '500001', '10,000'] as $input) {
    $check(parse_localized_positive_integer($input) === null, 'Invalid integer accepted: ' . $input);
}

$catalogue = array_values(wk_builtin_exercise_catalog());
$check(count($catalogue) >= 120, 'Built-in catalogue contains fewer than 120 exercises.');
$minimums = [
    'machine' => 24,
    'cable' => 12,
    'cardio_machine' => 8,
    'none' => 20,
    'outdoor' => 12,
    'dumbbell' => 16,
    'barbell' => 12,
    'kettlebell' => 8,
    'band' => 8,
];
$counts = array_fill_keys(array_keys($minimums), 0);
$slugs = [];
foreach ($catalogue as $exercise) {
    $slug = trim((string) ($exercise['slug'] ?? ''));
    $equipment = (string) ($exercise['equipment'] ?? '');
    if (isset($counts[$equipment])) {
        $counts[$equipment]++;
    }
    if ($equipment === 'bodyweight') {
        $counts['none']++;
    }
    $check($slug !== '' && !isset($slugs[$slug]), 'Missing or duplicate slug: ' . $slug);
    $slugs[$slug] = true;
    $check(trim((string) ($exercise['muscle'] ?? '')) !== '', 'Missing primary muscle: ' . $slug);
    $check(trim((string) ($exercise['difficulty'] ?? '')) !== '', 'Missing difficulty: ' . $slug);
    $check(is_array($exercise['guide']['en'] ?? null), 'Missing English guide: ' . $slug);

    $media = wk_catalog_media_defaults($exercise);
    $assetRelative = ltrim((string) $media['image_path'], '/');
    $assetPath = dirname(__DIR__) . '/public/' . preg_replace('~^assets/~', 'assets/', $assetRelative);
    $check(is_file($assetPath), 'Missing local WebP: ' . $assetRelative);
    $check(str_ends_with(strtolower($assetPath), '.webp'), 'Catalogue image is not WebP: ' . $assetRelative);
    $check(filter_var((string) $media['video_url'], FILTER_VALIDATE_URL) !== false, 'Missing public video URL: ' . $slug);
    $check(trim((string) $media['image_license']) !== '', 'Missing image licence: ' . $slug);
    $check(trim((string) $media['image_attribution']) !== '', 'Missing image attribution: ' . $slug);
    $check(trim((string) $media['video_attribution']) !== '', 'Missing video attribution: ' . $slug);
    $check($media['equipment_tags'] !== [], 'Missing equipment tags: ' . $slug);
    $check($media['contexts'] !== [], 'Missing context tags: ' . $slug);
}
foreach ($minimums as $equipment => $minimum) {
    $check(($counts[$equipment] ?? 0) >= $minimum, $equipment . ' coverage below ' . $minimum);
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'OK: localized goals and ' . count($catalogue) . ' catalogue exercises verified.' . PHP_EOL;
