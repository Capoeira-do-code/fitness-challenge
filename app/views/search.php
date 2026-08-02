<?php

declare(strict_types=1);

$query = trim((string) ($searchQuery ?? ''));
$people = array_values((array) ($searchUsers ?? []));
$exercises = array_values((array) ($searchExercises ?? []));
$routines = array_values((array) ($searchRoutines ?? []));
$resultCount = count($people) + count($exercises) + count($routines);
$searchAvatar = static function (array $user): void {
    $name = trim((string) ($user['display_name'] ?? $user['username'] ?? t('common.user')));
    $avatar = avatar_url($user);
    if ($avatar !== '') { ?><img src="<?= e($avatar) ?>" alt="" loading="lazy"><?php }
    else { ?><span><?= e(initials_for($name)) ?></span><?php }
};
$quickDestinations = [
    ['href' => '/?page=gallery&gallery_view=recent', 'icon' => 'image', 'label' => t('gallery.title'), 'hint' => t('social_hub.gallery_hint')],
    ['href' => '/?page=workouts&view=ranks', 'icon' => 'trophy', 'label' => t('workouts.tab_ranks'), 'hint' => t('workouts.rank_subtitle')],
    ['href' => '/?page=overview', 'icon' => 'grid', 'label' => t('overview.title'), 'hint' => t('overview.subtitle')],
    ['href' => '/?page=analytics', 'icon' => 'chart', 'label' => t('nav.analytics'), 'hint' => t('search.progress_hint')],
    ['href' => '/?page=friends', 'icon' => 'users', 'label' => t('nav.friends'), 'hint' => t('social_hub.friends_hint')],
    ['href' => '/?page=notifications', 'icon' => 'bell', 'label' => t('nav.notifications'), 'hint' => t('notifications.subtitle')],
];
?>
<section class="screen search-hub" aria-labelledby="search-hub-title">
    <header class="search-hub-head">
        <div><p class="eyebrow"><?= e(t('search.eyebrow')) ?></p><h1 id="search-hub-title"><?= e(t('nav.search')) ?></h1><p><?= e(t('search.subtitle')) ?></p></div>
    </header>

    <form class="search-hub-form" method="get" action="/" role="search">
        <input type="hidden" name="page" value="search">
        <span aria-hidden="true"><?= activity_icon_svg('search') ?></span>
        <label class="sr-only" for="global-search-query"><?= e(t('nav.search')) ?></label>
        <input id="global-search-query" type="search" name="q" value="<?= e($query) ?>" maxlength="80" placeholder="<?= e(t('search.placeholder')) ?>" autocomplete="off" enterkeyhint="search" autofocus>
        <?php if ($query !== ''): ?><a href="/?page=search" aria-label="<?= e(t('search.clear')) ?>">&times;</a><?php endif; ?>
        <button class="btn btn-primary" type="submit"><?= e(t('nav.search')) ?></button>
    </form>

    <?php if ($query === ''): ?>
        <section class="search-discover" aria-labelledby="search-discover-title">
            <div class="search-section-head"><div><p class="eyebrow"><?= e(t('search.discover_eyebrow')) ?></p><h2 id="search-discover-title"><?= e(t('search.discover')) ?></h2></div></div>
            <nav class="search-destination-grid" aria-label="<?= e(t('search.discover')) ?>">
                <?php foreach ($quickDestinations as $destination): ?>
                    <a href="<?= e((string) $destination['href']) ?>">
                        <span aria-hidden="true"><?= activity_icon_svg((string) $destination['icon']) ?></span>
                        <span><strong><?= e((string) $destination['label']) ?></strong><small><?= e((string) $destination['hint']) ?></small></span>
                        <b aria-hidden="true">&rsaquo;</b>
                    </a>
                <?php endforeach; ?>
            </nav>
        </section>
    <?php elseif ($resultCount === 0): ?>
        <div class="search-empty empty-state">
            <span class="empty-state-icon" aria-hidden="true"><?= activity_icon_svg('search') ?></span>
            <strong><?= e(t('search.empty_title')) ?></strong>
            <p><?= e(t('search.empty_hint', ['query' => $query])) ?></p>
        </div>
    <?php else: ?>
        <p class="search-result-summary"><?= e(t('search.result_count', ['count' => $resultCount, 'query' => $query])) ?></p>
        <div class="search-result-sections">
            <?php if ($people !== []): ?>
                <section class="search-result-group" aria-labelledby="search-people-title">
                    <div class="search-section-head"><h2 id="search-people-title"><?= e(t('search.people')) ?></h2><span><?= count($people) ?></span></div>
                    <div class="search-person-grid">
                        <?php foreach ($people as $person): ?>
                            <a class="search-person-card" href="/?page=profile&amp;user_id=<?= (int) ($person['id'] ?? 0) ?>">
                                <span class="search-person-avatar"><?php $searchAvatar($person); ?></span>
                                <span><strong><?= e((string) ($person['display_name'] ?? $person['username'] ?? '')) ?></strong><small>@<?= e((string) ($person['username'] ?? '')) ?></small></span>
                                <b aria-hidden="true">&rsaquo;</b>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

            <?php if ($exercises !== []): ?>
                <section class="search-result-group" aria-labelledby="search-exercises-title">
                    <div class="search-section-head"><h2 id="search-exercises-title"><?= e(t('search.exercises')) ?></h2><span><?= count($exercises) ?></span></div>
                    <div class="search-exercise-grid">
                        <?php foreach ($exercises as $exercise): ?>
                            <?php $exerciseImage = trim((string) ($exercise['image_path'] ?? '')); ?>
                            <a class="search-exercise-card" href="/?page=workouts&amp;view=library&amp;exercise_id=<?= (int) ($exercise['id'] ?? 0) ?>">
                                <span class="search-exercise-media"><?php if ($exerciseImage !== ''): ?><img src="<?= e(media_thumbnail_url($exerciseImage, 240)) ?>" alt="" loading="lazy"><?php else: ?><?= activity_icon_svg('dumbbell') ?><?php endif; ?></span>
                                <span><strong><?= e((string) ($exercise['display_name'] ?? $exercise['name'] ?? '')) ?></strong><small><?= e((string) ($exercise['muscle_group'] ?? '')) ?><?= trim((string) ($exercise['equipment'] ?? '')) !== '' ? ' · ' . e((string) $exercise['equipment']) : '' ?></small></span>
                                <b aria-hidden="true">&rsaquo;</b>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

            <?php if ($routines !== []): ?>
                <section class="search-result-group" aria-labelledby="search-routines-title">
                    <div class="search-section-head"><h2 id="search-routines-title"><?= e(t('search.routines')) ?></h2><span><?= count($routines) ?></span></div>
                    <div class="search-routine-grid">
                        <?php foreach ($routines as $routine): ?>
                            <a class="search-routine-card" href="/?page=workouts&amp;routine_id=<?= (int) ($routine['id'] ?? 0) ?>" style="--search-accent:<?= e((string) ($routine['accent_color'] ?? '#14b8a6')) ?>">
                                <span aria-hidden="true"><?= activity_icon_svg('dumbbell') ?></span>
                                <span><strong><?= e((string) ($routine['name'] ?? '')) ?></strong><small><?= e(t('search.exercise_count', ['count' => (int) ($routine['exercise_count'] ?? 0)])) ?></small></span>
                                <b aria-hidden="true">&rsaquo;</b>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</section>
