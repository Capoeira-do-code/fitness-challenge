<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/helpers.php';
require dirname(__DIR__) . '/app/i18n.php';
require dirname(__DIR__) . '/app/media_search.php';

$_SESSION = [];
$failures = 0;

function media_search_check(bool $condition, string $label): void
{
    global $failures;
    if ($condition) {
        echo "[ok] {$label}\n";
        return;
    }
    $failures++;
    echo "[fail] {$label}\n";
}

$config = [
    'media_search_google_api_key' => 'GOOGLE_SECRET_QA',
    'media_search_google_cx' => 'CX_QA',
    'media_search_youtube_api_key' => 'YOUTUBE_SECRET_QA',
];
$googleFixture = [
    'items' => [[
        'title' => '<b>Cable row setup</b>',
        'link' => 'https://images.example.test/row.jpg',
        'displayLink' => 'example.test',
        'image' => [
            'contextLink' => 'https://example.test/cable-row',
            'thumbnailLink' => 'https://images.example.test/row-thumb.jpg',
            'width' => 1280,
            'height' => 720,
        ],
    ]],
];
$requestedUrls = [];
$fakeGoogle = static function (string $url) use (&$requestedUrls, $googleFixture): array {
    $requestedUrls[] = $url;
    return $googleFixture;
};

$imageResults = media_search_query($config, 'image', 'cable row', 7, 'es', $fakeGoogle);
media_search_check(count($imageResults) === 1, 'Google image payload is normalized');
media_search_check(
    ($imageResults[0]['title'] ?? '') === 'Cable row setup'
    && !isset($imageResults[0]['url'])
    && !str_contains(json_encode($imageResults), 'GOOGLE_SECRET_QA'),
    'provider key and original image URL never reach the client'
);
media_search_check(
    count($requestedUrls) === 1
    && str_contains($requestedUrls[0], 'searchType=image')
    && str_contains($requestedUrls[0], 'safe=active'),
    'Google request enforces image and safe-search mode'
);

media_search_query($config, 'image', 'cable row', 7, 'es', $fakeGoogle);
media_search_check(count($requestedUrls) === 1, 'identical searches reuse the short-lived session cache');

$selectionToken = (string) ($imageResults[0]['id'] ?? '');
$selection = media_search_get_image_selection(7, $selectionToken);
media_search_check(
    strlen($selectionToken) === 36
    && ($selection['url'] ?? '') === 'https://images.example.test/row.jpg',
    'image selection uses an opaque short-lived token'
);
try {
    media_search_get_image_selection(8, $selectionToken);
    media_search_check(false, 'image token is bound to its authenticated user');
} catch (InvalidArgumentException) {
    media_search_check(true, 'image token is bound to its authenticated user');
}

$youtubeFixture = [
    'items' => [
        [
            'id' => ['videoId' => 'dQw4w9WgXcQ'],
            'snippet' => [
                'title' => 'Cable row &amp; form',
                'channelTitle' => 'Technique Lab',
                'publishedAt' => '2026-01-01T00:00:00Z',
                'thumbnails' => ['medium' => ['url' => 'https://i.ytimg.com/vi/dQw4w9WgXcQ/mqdefault.jpg']],
            ],
        ],
        ['id' => ['videoId' => 'unsafe'], 'snippet' => []],
    ],
];
$videoResults = media_search_normalize_youtube($youtubeFixture);
media_search_check(
    count($videoResults) === 1
    && ($videoResults[0]['url'] ?? '') === 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'
    && ($videoResults[0]['title'] ?? '') === 'Cable row & form',
    'YouTube results accept only valid video IDs and safe canonical URLs'
);
$youtubeRequest = '';
media_search_query($config, 'video', 'cable row tutorial', 7, 'es', static function (string $url) use (&$youtubeRequest, $youtubeFixture): array {
    $youtubeRequest = $url;
    return $youtubeFixture;
});
media_search_check(
    str_contains($youtubeRequest, 'safeSearch=strict')
    && str_contains($youtubeRequest, 'videoEmbeddable=true')
    && str_contains($youtubeRequest, 'videoSyndicated=true'),
    'YouTube search returns only safe videos playable outside YouTube'
);

$blockedUrls = [
    'http://example.com/image.jpg',
    'https://localhost/image.jpg',
    'https://127.0.0.1/image.jpg',
    'https://169.254.169.254/latest/meta-data',
    'https://10.0.0.1/image.jpg',
    'https://user:pass@example.com/image.jpg',
    'https://example.com:8443/image.jpg',
];
$blocked = 0;
foreach ($blockedUrls as $blockedUrl) {
    try {
        media_search_validate_public_image_url($blockedUrl);
    } catch (RuntimeException) {
        $blocked++;
    }
}
media_search_check($blocked === count($blockedUrls), 'image importer rejects local, private and malformed targets');
$publicTarget = media_search_validate_public_image_url('https://93.184.216.34/image.jpg');
media_search_check(($publicTarget['resolve'] ?? '') === '93.184.216.34:443:93.184.216.34', 'image importer pins a validated public destination');

try {
    media_search_query([], 'video', 'cable row', 7, 'es', static fn(string $url): array => []);
    media_search_check(false, 'missing provider credentials fail closed');
} catch (RuntimeException) {
    media_search_check(true, 'missing provider credentials fail closed');
}

foreach (SUPPORTED_LOCALES as $locale) {
    set_current_locale($locale);
    media_search_check(t('workouts.media_search_google') !== 'workouts.media_search_google', "media search copy exists for {$locale}");
}

set_current_locale('es');
$workoutMediaSearchType = 'image';
$workoutMediaSearchId = 'qa';
ob_start();
require dirname(__DIR__) . '/app/views/partials/workout_media_search.php';
$partial = (string) ob_get_clean();
media_search_check(
    str_contains($partial, 'data-workout-media-search')
    && str_contains($partial, 'api_workout_media_search')
    && !str_contains($partial, 'GOOGLE_SECRET_QA')
    && !str_contains($partial, 'YOUTUBE_SECRET_QA'),
    'rendered search UI exposes endpoints but never provider credentials'
);

if ($failures > 0) {
    fwrite(STDERR, "Media search QA: {$failures} failure(s)\n");
    exit(1);
}

echo "Media search QA: PASS\n";
