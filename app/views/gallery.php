<?php

declare(strict_types=1);

$photos = array_values((array) ($galleryPhotos ?? []));
$pageNum = max(1, (int) ($pageNum ?? 1));
$hasNextPage = !empty($hasNextPage);
$selectedUser = is_array($selectedUser ?? null) ? (array) $selectedUser : (array) ($currentUser ?? []);
$selectedUserId = (int) ($selectedUser['id'] ?? $currentUser['id'] ?? 0);
$baseParams = [
    'page' => 'gallery',
    'user_id' => $selectedUserId,
];
$calendarUrl = '/?' . http_build_query([
    'page' => 'entries',
    'mode' => 'calendar',
    'calendar_view' => 'month',
]);
$prevUrl = '/?' . http_build_query($baseParams + ['page_num' => max(1, $pageNum - 1)]);
$nextUrl = '/?' . http_build_query($baseParams + ['page_num' => $pageNum + 1]);
?>
<section class="screen gallery-page">
    <div class="gallery-toolbar">
        <div>
            <p class="eyebrow"><?= e(t('gallery.eyebrow')) ?></p>
            <h1><?= e(t('gallery.title')) ?></h1>
        </div>
        <div class="gallery-actions">
            <a class="btn btn-ghost small" href="/?page=entries&mode=calendar&calendar_view=month"><?= e(t('nav.calendar')) ?></a>
            <a class="btn btn-primary small" href="/?page=entries&mode=meal"><?= e(t('entries.create_entry')) ?></a>
        </div>
    </div>

    <nav class="photo-mode-segments" aria-label="<?= e(t('gallery.photo_mode')) ?>">
        <a class="active" href="/?<?= e(http_build_query($baseParams)) ?>" aria-current="page"><?= e(t('gallery.mode_recent')) ?></a>
        <a href="<?= e($calendarUrl) ?>"><?= e(t('gallery.mode_calendar')) ?></a>
    </nav>

    <?php if (is_admin($currentUser) && count((array) ($users ?? [])) > 1): ?>
        <form method="get" action="/" class="gallery-filter">
            <input type="hidden" name="page" value="gallery">
            <label>
                <?= e(t('dashboard.viewing')) ?>
                <select name="user_id" onchange="this.form.submit()">
                    <?php foreach ((array) $users as $user): ?>
                        <option value="<?= (int) ($user['id'] ?? 0) ?>" <?= (int) ($user['id'] ?? 0) === $selectedUserId ? 'selected' : '' ?>>
                            <?= e((string) ($user['display_name'] ?? '')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </form>
    <?php endif; ?>

    <?php if ($photos === []): ?>
        <div class="gallery-empty">
            <strong><?= e(t('gallery.empty_title')) ?></strong>
            <p><?= e(t('gallery.empty_body')) ?></p>
            <a class="btn btn-primary" href="/?page=entries&mode=meal"><?= e(t('entries.create_entry')) ?></a>
        </div>
    <?php else: ?>
        <div class="photos-gallery-grid">
            <?php foreach ($photos as $photo): ?>
                <?php
                $photoId = (int) ($photo['id'] ?? 0);
                $photoUrl = media_thumbnail_url((string) ($photo['file_path'] ?? ''), 420);
                $dateLabel = format_date_eu((string) ($photo['log_date'] ?? ''));
                ?>
                <a class="photos-gallery-tile" href="/?page=photo&photo_id=<?= $photoId ?>" aria-label="<?= e(t('common.photo')) ?> <?= e($dateLabel) ?>">
                    <?php if ($photoUrl !== ''): ?>
                        <img src="<?= e($photoUrl) ?>" alt="<?= e(t('common.photo')) ?>" loading="lazy" decoding="async">
                    <?php else: ?>
                        <span class="entries-calendar-empty"><?= e(t('entries.no_photo')) ?></span>
                    <?php endif; ?>
                    <span class="photos-gallery-date"><?= e($dateLabel) ?></span>
                </a>
            <?php endforeach; ?>
        </div>

        <nav class="gallery-pagination" aria-label="<?= e(t('gallery.pagination')) ?>">
            <?php if ($pageNum > 1): ?>
                <a class="btn btn-ghost" href="<?= e($prevUrl) ?>"><?= e(t('common.previous')) ?></a>
            <?php endif; ?>
            <?php if ($hasNextPage): ?>
                <a class="btn btn-primary" href="<?= e($nextUrl) ?>"><?= e(t('gallery.load_more')) ?></a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
</section>
