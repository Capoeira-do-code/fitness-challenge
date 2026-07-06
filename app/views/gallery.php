<?php

declare(strict_types=1);

$photos = array_values((array) ($galleryPhotos ?? []));
$selectedUser = is_array($selectedUser ?? null) ? (array) $selectedUser : [];
$isAllUsersGallery = is_admin($currentUser) && $selectedUser === [];
$selectedUserId = $isAllUsersGallery ? 0 : (int) ($selectedUser['id'] ?? $currentUser['id'] ?? 0);
$galleryView = in_array((string) ($galleryView ?? 'recent'), ['recent', 'calendar'], true) ? (string) $galleryView : 'recent';
$calendarView = in_array((string) ($calendarView ?? 'month'), ['month', 'week', 'day'], true) ? (string) $calendarView : 'month';
$selectedDate = to_date((string) ($selectedDate ?? null));
$mealCalendar = is_array($mealCalendar ?? null) ? (array) $mealCalendar : [];
$galleryPage = max(1, (int) ($galleryPage ?? 1));
$galleryPerPage = max(24, min(240, (int) ($galleryPerPage ?? 96)));
$galleryHasMore = !empty($galleryHasMore);
$galleryNextPage = isset($galleryNextPage) && $galleryNextPage !== null ? (int) $galleryNextPage : null;
$galleryMonthSeed = trim((string) ($galleryMonthSeed ?? ''));
$galleryApiUrl = trim((string) ($galleryApiUrl ?? '/?page=api_gallery_recent'));
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
$calendarSwitchUrl = '/?' . http_build_query($baseParams + [
    'gallery_view' => 'calendar',
    'calendar_view' => $galleryView === 'recent' ? 'month' : $calendarView,
    'date' => $selectedDate,
]);
$calendarVisibleLabel = $calendarView === 'month'
    ? localized_month_label($selectedDate)
    : ($calendarView === 'week' ? date_to_iso_week($selectedDate) : format_date_eu($selectedDate));
ob_start();
?>
<details class="topbar-context calendar-view-menu">
    <summary class="btn btn-ghost btn-topbar"><?= e(t('common.view')) ?></summary>
    <div class="topbar-context-panel calendar-view-panel">
        <form method="get" action="/" class="stack calendar-view-form" data-meal-calendar-form data-calendar-page="gallery">
            <input type="hidden" name="page" value="gallery">
            <input type="hidden" name="gallery_view" value="<?= e($galleryView) ?>">
            <input type="hidden" name="include_photos" value="0">
            <input type="hidden" value="<?= e($selectedDate) ?>" data-meal-calendar-date>
            <div class="view-panel-section">
                <span class="view-panel-label"><?= e(t('common.user')) ?></span>
                <?php if (is_admin($currentUser) && count((array) ($users ?? [])) > 1): ?>
                    <select name="user_id" onchange="this.form.submit()" aria-label="<?= e(t('dashboard.viewing')) ?>">
                        <option value="0" <?= $selectedUserId === 0 ? 'selected' : '' ?>>
                            <?= e(t('gallery.all_photos')) ?>
                        </option>
                        <?php foreach ((array) $users as $user): ?>
                            <option value="<?= (int) ($user['id'] ?? 0) ?>" <?= (int) ($user['id'] ?? 0) === $selectedUserId ? 'selected' : '' ?>>
                                <?= e((string) ($user['display_name'] ?? '')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <input type="hidden" name="user_id" value="<?= $selectedUserId ?>">
                    <span class="calendar-view-static"><?= e((string) ($selectedUser['display_name'] ?? '')) ?></span>
                <?php endif; ?>
            </div>
            <div class="view-panel-section">
                <?php if ($galleryView === 'calendar'): ?>
                    <span class="view-panel-label"><?= e(t('common.mode')) ?></span>
                <?php endif; ?>
                <nav class="calendar-view-segments" aria-label="<?= e(t('gallery.photo_mode')) ?>">
                    <a class="<?= $galleryView === 'recent' ? 'active' : '' ?>" href="<?= e($recentUrl) ?>" <?= $galleryView === 'recent' ? 'aria-current="page"' : '' ?>><?= e(t('gallery.mode_recent')) ?></a>
                    <a class="<?= $galleryView === 'calendar' ? 'active' : '' ?>" href="<?= e($calendarSwitchUrl) ?>" <?= $galleryView === 'calendar' ? 'aria-current="page"' : '' ?>><?= e(t('gallery.mode_calendar')) ?></a>
                </nav>
            </div>
            <?php if ($galleryView === 'calendar'): ?>
                <div class="view-panel-section">
                    <span class="view-panel-label"><?= e(t('calendar.view_mode')) ?></span>
                    <div class="calendar-view-segments" role="group" aria-label="<?= e(t('calendar.view_mode')) ?>">
                        <?php foreach (['month' => t('calendar.view_month'), 'week' => t('calendar.view_week'), 'day' => t('calendar.view_day')] as $viewKey => $viewLabel): ?>
                            <a class="<?= $calendarView === $viewKey ? 'active' : '' ?>" href="/?<?= e(http_build_query($baseParams + ['gallery_view' => 'calendar', 'calendar_view' => $viewKey, 'date' => $selectedDate])) ?>" data-calendar-view-option="<?= e($viewKey) ?>"><?= e($viewLabel) ?></a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php if ($calendarView === 'month'): ?>
                    <label class="view-panel-section">
                        <span data-meal-calendar-period-label><?= e(t('dashboard.month')) ?></span>
                        <input type="month" name="calendar_month" value="<?= e(substr($selectedDate, 0, 7)) ?>" onchange="this.form.submit()" data-meal-calendar-period data-label-month="<?= e(t('dashboard.month')) ?>" data-label-week="<?= e(t('common.week')) ?>" data-label-date="<?= e(t('common.date')) ?>">
                    </label>
                <?php elseif ($calendarView === 'week'): ?>
                    <label class="view-panel-section">
                        <span data-meal-calendar-period-label><?= e(t('common.week')) ?></span>
                        <input type="week" name="calendar_week" value="<?= e(date_to_iso_week($selectedDate)) ?>" onchange="this.form.submit()" data-meal-calendar-period data-label-month="<?= e(t('dashboard.month')) ?>" data-label-week="<?= e(t('common.week')) ?>" data-label-date="<?= e(t('common.date')) ?>">
                    </label>
                <?php else: ?>
                    <label class="view-panel-section">
                        <span data-meal-calendar-period-label><?= e(t('common.date')) ?></span>
                        <input type="date" name="date" value="<?= e($selectedDate) ?>" onchange="this.form.submit()" data-meal-calendar-period data-label-month="<?= e(t('dashboard.month')) ?>" data-label-week="<?= e(t('common.week')) ?>" data-label-date="<?= e(t('common.date')) ?>">
                    </label>
                <?php endif; ?>
                <input type="hidden" name="calendar_view" value="<?= e($calendarView) ?>" data-meal-calendar-view>
                <div class="view-panel-section">
                    <span class="view-panel-label"><?= e(t('common.actions')) ?></span>
                    <div class="calendar-view-actions">
                        <a class="btn btn-primary btn-block" href="/?page=entries&mode=meal&date=<?= e($selectedDate) ?>"><?= e(t('entries.create_entry')) ?></a>
                        <a class="btn btn-ghost btn-block" href="/?page=entries&mode=calendar&calendar_view=month&user_id=<?= $selectedUserId ?>"><?= e(t('entries.open_calendar')) ?></a>
                    </div>
                </div>
            <?php endif; ?>
        </form>
    </div>
</details>
<?php
$topbarControls = ob_get_clean();
?>
<section class="screen gallery-page gallery-page-clean">
    <?php if ($galleryView === 'calendar'): ?>
        <article class="panel entries-calendar-panel gallery-calendar-panel" data-meal-calendar-root data-calendar-page="gallery" data-user-id="<?= $selectedUserId ?>" data-include-photos="0">
            <?php if ($calendarView === 'month'): ?>
                <form method="get" action="/" class="calendar-visible-period-form" data-meal-calendar-visible-period-form>
                    <input type="hidden" name="page" value="gallery">
                    <input type="hidden" name="user_id" value="<?= $selectedUserId ?>">
                    <input type="hidden" name="gallery_view" value="calendar">
                    <input type="hidden" name="calendar_view" value="month">
                    <div class="calendar-visible-period" role="button" tabindex="0" data-meal-calendar-visible-period-trigger aria-label="<?= e(t('dashboard.month')) ?>">
                        <span data-meal-calendar-visible-period><?= e($calendarVisibleLabel) ?></span>
                        <input class="calendar-visible-period-input" type="month" name="calendar_month" value="<?= e(substr($selectedDate, 0, 7)) ?>" aria-label="<?= e(t('dashboard.month')) ?>" tabindex="-1" data-meal-calendar-visible-period-input>
                    </div>
                </form>
            <?php else: ?>
                <div class="calendar-visible-period" data-meal-calendar-visible-period><?= e($calendarVisibleLabel) ?></div>
            <?php endif; ?>
            <div class="meal-calendar meal-calendar-<?= e($calendarView) ?><?= $calendarView === 'month' ? ' meal-calendar-month' : '' ?> entries-calendar" data-meal-calendar-days>
                <?php foreach ($mealCalendar as $dateKey => $day): ?>
                    <?php
                    $photoCount = (int) ($day['count'] ?? 0);
                    $hasLog = $photoCount > 0;
                    $preview = is_array($day['preview'] ?? null) ? (array) $day['preview'] : null;
                    $previewPhotoId = (int) ($preview['id'] ?? 0);
                    try {
                        $calendarDayDate = new DateTimeImmutable((string) $dateKey);
                        $calendarDayLabel = $calendarView === 'month'
                            ? $calendarDayDate->format('j')
                            : ($calendarView === 'week' ? $calendarDayDate->format('d/m') : format_date_eu((string) $dateKey));
                    } catch (Throwable) {
                        $calendarDayLabel = (string) $dateKey;
                    }
                    $previewPhotos = [];
                    foreach (array_slice(array_values((array) ($day['photos'] ?? [])), 0, 3) as $previewPhoto) {
                        if (!is_array($previewPhoto)) {
                            continue;
                        }
                        $previewPath = (string) ($previewPhoto['file_path'] ?? '');
                        $previewImage = media_thumbnail_url($previewPath, 360);
                        if ($previewImage !== '') {
                            $previewPhotos[] = [
                                'src' => $previewImage,
                                'srcset' => media_thumbnail_srcset($previewPath, [200, 400, 800]),
                            ];
                        }
                    }
                    $calendarDayUrl = $previewPhotoId > 0
                        ? '/?page=photo&photo_id=' . $previewPhotoId
                        : '/?page=entries&mode=meal&date=' . rawurlencode((string) $dateKey);
                    ?>
                    <a class="entries-calendar-day<?= $hasLog ? ' has-log' : '' ?><?= (string) $dateKey === $selectedDate ? ' is-selected' : '' ?>" href="<?= e($calendarDayUrl) ?>">
                        <article>
                            <strong><?= e($calendarDayLabel) ?></strong>
                            <?php if ($previewPhotos !== []): ?>
                                <div class="entries-calendar-collage collage-count-<?= min(3, count($previewPhotos)) ?>">
                                    <?php foreach ($previewPhotos as $previewPhotoImage): ?>
                                        <img src="<?= e((string) ($previewPhotoImage['src'] ?? '')) ?>" srcset="<?= e((string) ($previewPhotoImage['srcset'] ?? '')) ?>" sizes="(max-width: 600px) 24vw, 140px" width="400" height="400" alt="<?= e(t('common.photo')) ?>" loading="lazy" decoding="async">
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
    <?php elseif ($photos === []): ?>
        <div class="gallery-empty">
            <strong><?= e(t('gallery.empty_title')) ?></strong>
            <p><?= e(t('gallery.empty_body')) ?></p>
            <a class="btn btn-primary" href="/?page=entries&mode=meal"><?= e(t('entries.create_entry')) ?></a>
        </div>
    <?php else: ?>
        <div
            class="gallery-recent-root"
            data-gallery-recent-root
            data-gallery-recent-api="<?= e($galleryApiUrl) ?>"
            data-gallery-user-id="<?= (int) $selectedUserId ?>"
            data-gallery-page="<?= (int) $galleryPage ?>"
            data-gallery-per-page="<?= (int) $galleryPerPage ?>"
            data-gallery-next-page="<?= $galleryNextPage !== null ? (int) $galleryNextPage : '' ?>"
            data-gallery-has-more="<?= $galleryHasMore ? '1' : '0' ?>"
            data-gallery-no-photo-label="<?= e(t('entries.no_photo')) ?>"
            data-gallery-photo-label="<?= e(t('common.photo')) ?>"
        >
            <div class="gallery-month-floating" data-gallery-month-floating hidden></div>
            <div class="photos-gallery-grid photos-gallery-grid-continuous" data-gallery-recent-grid>
                <?php $currentMonth = $galleryMonthSeed; ?>
                <?php foreach ($photos as $photoIndex => $photo): ?>
                    <?php
                    $photoId = (int) ($photo['id'] ?? 0);
                    $photoPath = (string) ($photo['file_path'] ?? '');
                    $photoUrl = media_thumbnail_url($photoPath, 400);
                    $photoSrcset = media_thumbnail_srcset($photoPath, [200, 400, 800]);
                    $photoLoading = $photoIndex < 24 ? 'eager' : 'lazy';
                    $photoFetchPriority = $photoIndex < 12 ? 'high' : 'low';
                    $date = (string) ($photo['log_date'] ?? '');
                    $dateLabel = format_date_eu($date);
                    $monthKey = substr($date, 0, 7);
                    $monthLabel = localized_month_label($date);
                    $isFirstInMonth = $monthKey !== '' && $monthKey !== $currentMonth;
                    if ($monthKey !== '') {
                        $currentMonth = $monthKey;
                    }
                    ?>
                    <a class="photos-gallery-tile" href="/?page=photo&photo_id=<?= $photoId ?>" aria-label="<?= e(t('common.photo')) ?> <?= e($dateLabel) ?>" data-month-label="<?= e($monthLabel) ?>"<?= $isFirstInMonth ? ' data-month-start="1"' : '' ?>>
                        <?php if ($photoUrl !== ''): ?>
                            <img src="<?= e($photoUrl) ?>" srcset="<?= e($photoSrcset) ?>" sizes="(max-width: 700px) 33vw, (max-width: 1100px) 20vw, 170px" width="400" height="400" alt="<?= e(t('common.photo')) ?>" loading="<?= e($photoLoading) ?>" fetchpriority="<?= e($photoFetchPriority) ?>" decoding="async">
                        <?php else: ?>
                            <span class="entries-calendar-empty"><?= e(t('entries.no_photo')) ?></span>
                        <?php endif; ?>
                        <span class="photos-gallery-date"><?= e($dateLabel) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
            <button class="btn btn-ghost gallery-load-more" type="button" data-gallery-recent-load-more<?= $galleryHasMore ? '' : ' hidden' ?>><?= e(t('gallery.load_more')) ?></button>
            <div class="gallery-recent-sentinel" data-gallery-recent-sentinel aria-hidden="true"></div>
        </div>
    <?php endif; ?>
</section>
