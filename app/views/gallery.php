<?php

declare(strict_types=1);

$photos = array_values((array) ($galleryPhotos ?? []));
$selectedUser = is_array($selectedUser ?? null) ? (array) $selectedUser : (array) ($currentUser ?? []);
$selectedUserId = (int) ($selectedUser['id'] ?? $currentUser['id'] ?? 0);
$galleryView = in_array((string) ($galleryView ?? 'recent'), ['recent', 'calendar'], true) ? (string) $galleryView : 'recent';
$calendarView = in_array((string) ($calendarView ?? 'month'), ['month', 'week', 'day'], true) ? (string) $calendarView : 'month';
$selectedDate = to_date((string) ($selectedDate ?? null));
$mealCalendar = is_array($mealCalendar ?? null) ? (array) $mealCalendar : [];
$baseParams = [
    'page' => 'gallery',
    'user_id' => $selectedUserId,
];
$recentUrl = '/?' . http_build_query($baseParams + ['gallery_view' => 'recent']);
$calendarUrl = '/?' . http_build_query($baseParams + [
    'gallery_view' => 'calendar',
    'calendar_view' => $calendarView,
    'date' => $selectedDate,
]);
$monthLabel = static function (string $date): string {
    try {
        $dt = new DateTimeImmutable($date);
    } catch (Throwable) {
        $dt = new DateTimeImmutable('today');
    }
    $month = (int) $dt->format('n');
    $year = $dt->format('Y');
    $locale = current_locale();
    $months = str_starts_with($locale, 'es')
        ? [1 => 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre']
        : [1 => 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

    return ($months[$month] ?? $dt->format('F')) . ' ' . $year;
};
$calendarVisibleLabel = $calendarView === 'month'
    ? $monthLabel($selectedDate)
    : ($calendarView === 'week' ? date_to_iso_week($selectedDate) : format_date_eu($selectedDate));
$periodPhotos = [];
$selectedPhotos = [];
foreach ($mealCalendar as $day) {
    foreach (array_values((array) ($day['photos'] ?? [])) as $photo) {
        if (is_array($photo)) {
            $periodPhotos[] = $photo;
        }
    }
}
usort(
    $periodPhotos,
    static function (array $left, array $right): int {
        $dateCompare = strcmp((string) ($right['log_date'] ?? ''), (string) ($left['log_date'] ?? ''));
        if ($dateCompare !== 0) {
            return $dateCompare;
        }

        return strcmp((string) ($right['created_at'] ?? ''), (string) ($left['created_at'] ?? ''));
    }
);
$selectedDayData = is_array($mealCalendar[$selectedDate] ?? null) ? (array) $mealCalendar[$selectedDate] : [];
foreach (array_values((array) ($selectedDayData['photos'] ?? [])) as $photo) {
    if (is_array($photo)) {
        $selectedPhotos[] = $photo;
    }
}
?>
<section class="screen gallery-page gallery-page-clean">
    <div class="gallery-view-strip">
        <form method="get" action="/" class="gallery-user-control">
            <input type="hidden" name="page" value="gallery">
            <input type="hidden" name="gallery_view" value="<?= e($galleryView) ?>">
            <?php if ($galleryView === 'calendar'): ?>
                <input type="hidden" name="calendar_view" value="<?= e($calendarView) ?>">
                <input type="hidden" name="date" value="<?= e($selectedDate) ?>">
            <?php endif; ?>
            <span><?= e(t('dashboard.viewing')) ?></span>
            <?php if (is_admin($currentUser) && count((array) ($users ?? [])) > 1): ?>
                <select name="user_id" onchange="this.form.submit()" aria-label="<?= e(t('dashboard.viewing')) ?>">
                    <?php foreach ((array) $users as $user): ?>
                        <option value="<?= (int) ($user['id'] ?? 0) ?>" <?= (int) ($user['id'] ?? 0) === $selectedUserId ? 'selected' : '' ?>>
                            <?= e((string) ($user['display_name'] ?? '')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php else: ?>
                <strong><?= e((string) ($selectedUser['display_name'] ?? '')) ?></strong>
            <?php endif; ?>
        </form>

        <nav class="gallery-segment-control" aria-label="<?= e(t('gallery.photo_mode')) ?>">
            <a class="<?= $galleryView === 'recent' ? 'active' : '' ?>" href="<?= e($recentUrl) ?>" <?= $galleryView === 'recent' ? 'aria-current="page"' : '' ?>><?= e(t('gallery.mode_recent')) ?></a>
            <a class="<?= $galleryView === 'calendar' ? 'active' : '' ?>" href="<?= e($calendarUrl) ?>" <?= $galleryView === 'calendar' ? 'aria-current="page"' : '' ?>><?= e(t('gallery.mode_calendar')) ?></a>
        </nav>
    </div>

    <?php if ($galleryView === 'calendar'): ?>
        <article class="panel entries-calendar-panel gallery-calendar-panel" data-meal-calendar-root data-calendar-page="gallery" data-user-id="<?= $selectedUserId ?>">
            <form method="get" action="/" class="control-strip entries-calendar-controls" data-meal-calendar-form>
                <input type="hidden" name="page" value="gallery">
                <input type="hidden" name="gallery_view" value="calendar">
                <input type="hidden" name="user_id" value="<?= $selectedUserId ?>">
                <input type="hidden" value="<?= e($selectedDate) ?>" data-meal-calendar-date>
                <?php if ($calendarView === 'month'): ?>
                    <label class="entry-date-inline">
                        <span data-meal-calendar-period-label><?= e(t('dashboard.month')) ?></span>
                        <input type="month" name="calendar_month" value="<?= e(substr($selectedDate, 0, 7)) ?>" onchange="this.form.submit()" data-meal-calendar-period data-label-month="<?= e(t('dashboard.month')) ?>" data-label-week="<?= e(t('common.week')) ?>" data-label-date="<?= e(t('common.date')) ?>">
                    </label>
                <?php elseif ($calendarView === 'week'): ?>
                    <label class="entry-date-inline">
                        <span data-meal-calendar-period-label><?= e(t('common.week')) ?></span>
                        <input type="week" name="calendar_week" value="<?= e(date_to_iso_week($selectedDate)) ?>" onchange="this.form.submit()" data-meal-calendar-period data-label-month="<?= e(t('dashboard.month')) ?>" data-label-week="<?= e(t('common.week')) ?>" data-label-date="<?= e(t('common.date')) ?>">
                    </label>
                <?php else: ?>
                    <label class="entry-date-inline">
                        <span data-meal-calendar-period-label><?= e(t('common.date')) ?></span>
                        <input type="date" name="date" value="<?= e($selectedDate) ?>" onchange="this.form.submit()" data-meal-calendar-period data-label-month="<?= e(t('dashboard.month')) ?>" data-label-week="<?= e(t('common.week')) ?>" data-label-date="<?= e(t('common.date')) ?>">
                    </label>
                <?php endif; ?>
                <input type="hidden" name="calendar_view" value="<?= e($calendarView) ?>" data-meal-calendar-view>
                <div class="calendar-view-segments" role="group" aria-label="<?= e(t('calendar.view_mode')) ?>">
                    <?php foreach (['month' => t('calendar.view_month'), 'week' => t('calendar.view_week'), 'day' => t('calendar.view_day')] as $viewKey => $viewLabel): ?>
                        <a class="<?= $calendarView === $viewKey ? 'active' : '' ?>" href="/?<?= e(http_build_query($baseParams + ['gallery_view' => 'calendar', 'calendar_view' => $viewKey, 'date' => $selectedDate])) ?>" data-calendar-view-option="<?= e($viewKey) ?>"><?= e($viewLabel) ?></a>
                    <?php endforeach; ?>
                </div>
            </form>
            <div class="calendar-visible-period" data-meal-calendar-visible-period><?= e($calendarVisibleLabel) ?></div>
            <div class="meal-calendar meal-calendar-<?= e($calendarView) ?><?= $calendarView === 'month' ? ' meal-calendar-month' : '' ?> entries-calendar" data-meal-calendar-days>
                <?php foreach ($mealCalendar as $dateKey => $day): ?>
                    <?php
                    $photoCount = (int) ($day['count'] ?? 0);
                    $hasLog = $photoCount > 0;
                    $preview = is_array($day['preview'] ?? null) ? (array) $day['preview'] : null;
                    $previewPhotoId = (int) ($preview['id'] ?? 0);
                    $previewPhotos = [];
                    foreach (array_slice(array_values((array) ($day['photos'] ?? [])), 0, 3) as $previewPhoto) {
                        if (!is_array($previewPhoto)) {
                            continue;
                        }
                        $previewImage = media_thumbnail_url((string) ($previewPhoto['file_path'] ?? ''), 360);
                        if ($previewImage !== '') {
                            $previewPhotos[] = $previewImage;
                        }
                    }
                    $calendarDayUrl = $previewPhotoId > 0
                        ? '/?page=photo&photo_id=' . $previewPhotoId
                        : '/?page=entries&mode=meal&date=' . rawurlencode((string) $dateKey);
                    ?>
                    <a class="entries-calendar-day<?= $hasLog ? ' has-log' : '' ?><?= (string) $dateKey === $selectedDate ? ' is-selected' : '' ?>" href="<?= e($calendarDayUrl) ?>">
                        <article>
                            <strong><?= e(format_date_eu((string) $dateKey)) ?></strong>
                            <?php if ($previewPhotos !== []): ?>
                                <div class="entries-calendar-collage collage-count-<?= min(3, count($previewPhotos)) ?>">
                                    <?php foreach ($previewPhotos as $previewImage): ?>
                                        <img src="<?= e($previewImage) ?>" alt="<?= e(t('common.photo')) ?>" loading="lazy" decoding="async">
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="entries-calendar-empty"><?= e(t('entries.no_photo')) ?></div>
                            <?php endif; ?>
                            <span class="badge"><?= $photoCount ?> <?= e($photoCount === 1 ? t('entries.photo_singular') : t('entries.photo_plural')) ?></span>
                        </article>
                    </a>
                <?php endforeach; ?>
            </div>
        </article>

        <article class="panel entries-calendar-mobile-gallery-panel gallery-calendar-side-panel" data-meal-calendar-period-panel>
            <div class="panel-head">
                <div>
                    <p class="eyebrow"><?= e(t('nav.calendar')) ?></p>
                    <h2><?= e(t('entries.recent_photos')) ?></h2>
                </div>
                <span class="badge" data-meal-calendar-period-count><?= count($periodPhotos) ?> <?= e(count($periodPhotos) === 1 ? t('entries.photo_singular') : t('entries.photo_plural')) ?></span>
            </div>
            <div data-meal-calendar-period-photos>
                <?php if ($periodPhotos === []): ?>
                    <div class="calendar-empty-state">
                        <strong><?= e(t('gallery.empty_period_title')) ?></strong>
                        <p><?= e(t('gallery.empty_period_body')) ?></p>
                    </div>
                <?php else: ?>
                    <div class="entries-calendar-mobile-gallery">
                        <?php foreach ($periodPhotos as $photo): ?>
                            <?php $photoUrl = media_thumbnail_url((string) ($photo['file_path'] ?? ''), 360); ?>
                            <a class="entries-calendar-mobile-tile" href="/?page=photo&photo_id=<?= (int) ($photo['id'] ?? 0) ?>" data-date-label="<?= e(format_date_eu((string) ($photo['log_date'] ?? ''))) ?>">
                                <?php if ($photoUrl !== ''): ?>
                                    <img src="<?= e($photoUrl) ?>" alt="<?= e(t('common.photo')) ?>" loading="lazy" decoding="async">
                                <?php else: ?>
                                    <div class="entries-calendar-empty"><?= e(t('entries.no_photo')) ?></div>
                                <?php endif; ?>
                                <span><?= e(format_date_eu((string) ($photo['log_date'] ?? ''))) ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </article>

        <article class="panel entries-calendar-photos-panel gallery-calendar-side-panel" data-meal-calendar-photos-panel>
            <div class="panel-head">
                <div>
                    <p class="eyebrow"><?= e(t('common.date')) ?> · <?= e(format_date_eu($selectedDate)) ?></p>
                    <h2><?= e(t('entries.recent_photos')) ?></h2>
                </div>
            </div>
            <div data-meal-calendar-selected-photos>
                <?php if ($selectedPhotos === []): ?>
                    <div class="calendar-empty-state">
                        <strong><?= e(t('gallery.empty_period_title')) ?></strong>
                        <p><?= e(t('gallery.empty_period_body')) ?></p>
                    </div>
                <?php else: ?>
                    <div class="photo-grid">
                        <?php foreach ($selectedPhotos as $photo): ?>
                            <?php $photoUrl = media_url((string) ($photo['file_path'] ?? '')); ?>
                            <figure class="photo-card">
                                <a class="photo-card-media" href="/?page=photo&photo_id=<?= (int) ($photo['id'] ?? 0) ?>">
                                    <?php if ($photoUrl !== ''): ?>
                                        <img src="<?= e($photoUrl) ?>" alt="<?= e(t('common.photo')) ?>" loading="lazy" decoding="async">
                                    <?php else: ?>
                                        <span><?= e(t('entries.no_photo')) ?></span>
                                    <?php endif; ?>
                                </a>
                            </figure>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </article>
    <?php elseif ($photos === []): ?>
        <div class="gallery-empty">
            <strong><?= e(t('gallery.empty_title')) ?></strong>
            <p><?= e(t('gallery.empty_body')) ?></p>
            <a class="btn btn-primary" href="/?page=entries&mode=meal"><?= e(t('entries.create_entry')) ?></a>
        </div>
    <?php else: ?>
        <div class="photos-gallery-grid photos-gallery-grid-continuous">
            <?php $currentMonth = ''; ?>
            <?php foreach ($photos as $photo): ?>
                <?php
                $photoId = (int) ($photo['id'] ?? 0);
                $photoUrl = media_thumbnail_url((string) ($photo['file_path'] ?? ''), 420);
                $date = (string) ($photo['log_date'] ?? '');
                $dateLabel = format_date_eu($date);
                $monthKey = substr($date, 0, 7);
                if ($monthKey !== $currentMonth):
                    $currentMonth = $monthKey;
                ?>
                    <div class="gallery-month-label"><?= e($monthLabel($date)) ?></div>
                <?php endif; ?>
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
    <?php endif; ?>
</section>
