<?php
$feedItems = array_values((array) ($dashboardFeedItems ?? []));
$feedScope = (string) ($dashboardFeedScope ?? 'friends') === 'global' ? 'global' : 'friends';
$feedAvatar = static function (array $item): void {
    $url = avatar_url($item);
    $name = trim((string) ($item['display_name'] ?? $item['username'] ?? t('common.user')));
    if ($url !== '') { ?><img src="<?= e($url) ?>" alt="<?= e($name) ?>" loading="lazy"><?php }
    else { ?><span><?= e(initials_for($name)) ?></span><?php }
};
$feedNumber = static fn(float $number, string $suffix = ''): string => number_format($number, abs($number - round($number)) < .01 ? 0 : 1, ',', '.') . $suffix;
$feedOpenComments = trim((string) ($_GET['comments'] ?? ''));
$feedFocused = !empty($dashboardFeedFocused);
$feedViewerId = (int) ($currentUser['id'] ?? 0);
$feedRootPath = '/?page=dashboard&feed=' . rawurlencode($feedScope) . '#home-social-feed';
$feedWithOrigin = static function (string $path, string $returnPath): string {
    if ($path === '') return '';
    [$base, $fragment] = array_pad(explode('#', $path, 2), 2, '');
    $separator = str_contains($base, '?') ? '&' : '?';
    $target = $base . $separator . http_build_query([
        'origin' => 'home_feed',
        'return_to' => $returnPath,
    ]);
    return $fragment !== '' ? $target . '#' . $fragment : $target;
};
?>
<section class="home-social-feed<?= $feedFocused ? ' is-focused' : '' ?>" id="home-social-feed" aria-labelledby="home-social-feed-title">
    <header class="home-feed-head">
        <h2 class="sr-only" id="home-social-feed-title"><?= e(t('feed.title')) ?></h2>
        <?php if ($feedFocused): ?>
            <a class="home-feed-focus-back" href="<?= e($feedRootPath) ?>"><span aria-hidden="true">&larr;</span><strong><?= e(t('feed.title')) ?></strong></a>
        <?php else: ?>
            <nav class="home-feed-scope" aria-label="<?= e(t('feed.scope')) ?>"><a href="/?page=dashboard&amp;home=feed&amp;feed=friends#home-social-feed"<?= $feedScope === 'friends' ? ' aria-current="page"' : '' ?>><?= e(t('feed.friends')) ?></a><a href="/?page=dashboard&amp;home=feed&amp;feed=global#home-social-feed"<?= $feedScope === 'global' ? ' aria-current="page"' : '' ?>><?= e(t('feed.global')) ?></a></nav>
        <?php endif; ?>
    </header>
    <?php if ($feedItems === []): ?>
        <div class="home-feed-empty"><span aria-hidden="true"><?= activity_icon_svg('users') ?></span><h3><?= e(t('feed.empty')) ?></h3><p><?= e(t('feed.empty_hint')) ?></p><a class="btn btn-primary" href="/?page=friends"><?= e(t('social_hub.quick_friend')) ?></a></div>
    <?php else: ?>
        <div class="home-feed-stream">
        <?php foreach ($feedItems as $feedItem): ?>
            <?php
            $feedType = (string) ($feedItem['type'] ?? '');
            $feedId = (int) ($feedItem['id'] ?? 0);
            $feedShareTitle = $feedType === 'workout'
                ? (wk_session_display_title($feedItem) ?: t('workouts.session'))
                : (trim((string) ($feedItem[$feedType === 'photo' ? 'caption' : 'notes'] ?? '')) ?: t('feed.meal_update'));
            $feedFocusedPath = '/?page=dashboard&home=feed&feed=' . rawurlencode($feedScope) . '&post_type=' . rawurlencode($feedType) . '&post_id=' . $feedId . '#feed-' . rawurlencode($feedType) . '-' . $feedId;
            // Detail screens return to the full stream at the original card,
            // not to a one-post focus route that loses the surrounding feed.
            // This also leaves a useful Home screen after the owner deletes
            // the item and its former anchor no longer exists.
            $feedReturnPath = '/?page=dashboard&home=feed&feed=' . rawurlencode($feedScope) . '#feed-' . rawurlencode($feedType) . '-' . $feedId;
            $feedLinkedMealId = $feedType === 'photo' ? max(0, (int) ($feedItem['meal_entry_id'] ?? 0)) : 0;
            $feedMealEntryId = $feedType === 'meal' ? $feedId : $feedLinkedMealId;
            $feedMealDate = to_date((string) ($feedItem['entry_date'] ?? $feedItem['meal_entry_date'] ?? $feedItem['occurred_at'] ?? ''));
            $feedMealIsOwner = $feedMealEntryId > 0 && (int) ($feedItem['user_id'] ?? 0) === $feedViewerId;
            $feedDetailPath = match ($feedType) {
                'photo' => $feedWithOrigin($feedLinkedMealId > 0 ? '/?page=meal&meal_id=' . $feedLinkedMealId : '/?page=photo&photo_id=' . $feedId, $feedReturnPath),
                'workout' => $feedWithOrigin(trim((string) ($feedItem['detail_url'] ?? '')) ?: $feedFocusedPath, $feedReturnPath),
                'meal' => $feedWithOrigin('/?page=meal&meal_id=' . $feedId, $feedReturnPath),
                default => $feedFocusedPath,
            };
            $feedSharePath = match ($feedType) {
                'photo' => $feedLinkedMealId > 0 ? '/?page=meal&meal_id=' . $feedLinkedMealId : '/?page=photo&photo_id=' . $feedId,
                'workout' => trim((string) ($feedItem['share_url'] ?? '')) ?: $feedFocusedPath,
                'meal' => '/?page=meal&meal_id=' . $feedId,
                default => $feedFocusedPath,
            };
            ?>
            <article class="home-feed-post" id="feed-<?= e($feedType) ?>-<?= $feedId ?>">
                <header class="home-feed-author"><a href="/?page=profile&amp;user_id=<?= (int) ($feedItem['user_id'] ?? 0) ?>"><?php $feedAvatar($feedItem); ?></a><div><strong><?= e((string) ($feedItem['display_name'] ?? $feedItem['username'] ?? '')) ?></strong><time datetime="<?= e((string) ($feedItem['occurred_at'] ?? '')) ?>"><?= e(human_time_ago((string) ($feedItem['occurred_at'] ?? ''))) ?></time></div></header>
                <?php if ($feedType === 'workout'): ?>
                    <?php
                    $feedTitle = wk_session_display_title($feedItem) ?: t('workouts.session');
                    $feedStarted = strtotime((string) ($feedItem['occurred_at'] ?? '')) ?: 0;
                    $feedEnded = strtotime((string) ($feedItem['ended_at'] ?? '')) ?: $feedStarted;
                    $feedDuration = max(0, (int) round(($feedEnded - $feedStarted) / 60));
                    $feedWorkoutDetailUrl = $feedDetailPath;
                    ?>
                    <div class="home-feed-workout"><h3><?php if ($feedWorkoutDetailUrl !== ''): ?><a class="home-feed-workout-title" href="<?= e($feedWorkoutDetailUrl) ?>" aria-label="<?= e(t('workouts.session_summary')) ?>: <?= e($feedTitle) ?>"><span><?= e($feedTitle) ?></span><b aria-hidden="true">&rsaquo;</b></a><?php else: ?><?= e($feedTitle) ?><?php endif; ?></h3><div class="home-feed-workout-stats"><span><small><?= e(t('feed.time')) ?></small><strong><?= $feedDuration >= 60 ? intdiv($feedDuration, 60) . 'h ' . ($feedDuration % 60) . 'min' : $feedDuration . 'min' ?></strong></span><span><small><?= e(t('workouts.stat_volume')) ?></small><strong><?= e($feedNumber((float) ($feedItem['volume'] ?? 0), ' kg')) ?></strong></span><span><small><?= e(t('workouts.stat_sets')) ?></small><strong><?= (int) ($feedItem['set_count'] ?? 0) ?></strong></span></div>
                    <ul data-feed-exercise-list><?php foreach ((array) ($feedItem['exercises'] ?? []) as $exerciseIndex => $exercise): $doneSets = count(array_filter((array) ($exercise['sets'] ?? []), static fn(array $set): bool => (int) ($set['completed'] ?? 0) === 1)); $exerciseImage = trim((string) ($exercise['image_path'] ?? '')); ?><li<?= $exerciseIndex >= 3 ? ' hidden data-feed-extra-exercise' : '' ?>><?php if ($feedWorkoutDetailUrl !== ''): ?><a href="<?= e($feedWorkoutDetailUrl) ?>" aria-label="<?= e(t('workouts.session_summary')) ?>: <?= e($feedTitle) ?>"><?php else: ?><span class="home-feed-exercise-static"><?php endif; ?><?php if ($exerciseImage !== ''): ?><img src="<?= e(media_thumbnail_url($exerciseImage, 120)) ?>" alt="" loading="lazy"><?php else: ?><span aria-hidden="true"><?= activity_icon_svg('dumbbell') ?></span><?php endif; ?><strong><?= $doneSets ?> <?= e(t('workouts.stat_sets')) ?> <?= e((string) ($exercise['exercise_name'] ?? '')) ?></strong><?php if ($feedWorkoutDetailUrl !== ''): ?><b aria-hidden="true">&rsaquo;</b></a><?php else: ?></span><?php endif; ?></li><?php endforeach; ?></ul>
                    <?php $remainingExercises = max(0, count((array) ($feedItem['exercises'] ?? [])) - 3); if ($remainingExercises > 0): ?><button class="home-feed-more" type="button" data-feed-exercises-toggle data-label-more="<?= e(t('feed.more_exercises', ['count' => $remainingExercises])) ?>" data-label-less="<?= e(t('feed.fewer_exercises')) ?>" aria-expanded="false"><?= e(t('feed.more_exercises', ['count' => $remainingExercises])) ?></button><?php endif; ?></div>
                <?php elseif ($feedType === 'photo'): ?>
                    <?php $feedPhotoTitle = trim((string) ($feedItem['caption'] ?? '')) ?: t('feed.meal_update'); ?>
                    <div class="home-feed-photo-copy"><h3><a class="home-feed-post-title" href="<?= e($feedDetailPath) ?>" aria-label="<?= e($feedPhotoTitle) ?>"><span><?= e($feedPhotoTitle) ?></span><b aria-hidden="true">&rsaquo;</b></a></h3><?php if ((float) ($feedItem['calories'] ?? 0) > 0): ?><p><strong><?= e($feedNumber((float) $feedItem['calories'])) ?> kcal</strong><?php if ((float) ($feedItem['protein_g'] ?? 0) > 0): ?> · <?= e($feedNumber((float) $feedItem['protein_g'], ' g')) ?> <?= e(t('feed.protein')) ?><?php endif; ?></p><?php endif; ?></div>
                    <a class="home-feed-photo" href="<?= e($feedDetailPath) ?>"><img src="<?= e(media_thumbnail_url((string) ($feedItem['file_path'] ?? ''), 900)) ?>" alt="<?= e((string) ($feedItem['caption'] ?? '')) ?>" loading="lazy"></a>
                <?php else: ?>
                    <?php $feedMealTitle = trim((string) ($feedItem['notes'] ?? '')) ?: ucfirst((string) ($feedItem['meal_type'] ?? t('feed.meal_update'))); ?>
                    <div class="home-feed-meal"><span aria-hidden="true"><?= activity_icon_svg('image') ?></span><div><p class="eyebrow"><?= e(t('feed.meal_update')) ?></p><h3><a class="home-feed-post-title" href="<?= e($feedDetailPath) ?>" aria-label="<?= e($feedMealTitle) ?>"><span><?= e($feedMealTitle) ?></span><b aria-hidden="true">&rsaquo;</b></a></h3><strong><?= e($feedNumber((float) ($feedItem['calories'] ?? 0))) ?> kcal</strong><p><?= e($feedNumber((float) ($feedItem['protein_g'] ?? 0), ' g')) ?> P · <?= e($feedNumber((float) ($feedItem['carbs_g'] ?? 0), ' g')) ?> C · <?= e($feedNumber((float) ($feedItem['fat_g'] ?? 0), ' g')) ?> G</p></div></div>
                <?php endif; ?>
                <div class="home-feed-actions">
                    <form method="post" action="/?page=dashboard" data-feed-like-form data-allow-multi-submit><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="social_feed_like"><input type="hidden" name="entity_type" value="<?= e($feedType) ?>"><input type="hidden" name="entity_id" value="<?= $feedId ?>"><input type="hidden" name="feed_scope" value="<?= e($feedScope) ?>"><button type="submit" class="home-feed-like<?= !empty($feedItem['liked']) ? ' is-liked' : '' ?>" aria-label="<?= e(t('feed.like')) ?>" aria-pressed="<?= !empty($feedItem['liked']) ? 'true' : 'false' ?>"><?= activity_icon_svg('heart') ?><span data-feed-like-count><?= (int) ($feedItem['like_count'] ?? 0) ?></span></button></form>
                    <button type="button" data-feed-comment-toggle aria-expanded="<?= $feedOpenComments === $feedType . '-' . $feedId ? 'true' : 'false' ?>" aria-label="<?= e(t('feed.comment')) ?>"><?= activity_icon_svg('message') ?><span data-feed-comment-count data-social-comment-count><?= (int) ($feedItem['comment_count'] ?? count((array) ($feedItem['comments'] ?? []))) ?></span></button>
                    <button type="button" data-feed-share data-share-title="<?= e($feedShareTitle) ?>" data-share-text="<?= e($feedShareTitle) ?>" data-share-url="<?= e($feedSharePath) ?>" aria-label="<?= e(t('feed.share')) ?>"><?= activity_icon_svg('share') ?><span class="sr-only"><?= e(t('feed.share')) ?></span></button>
                    <?php if ($feedMealIsOwner): ?>
                        <form method="post" action="/?page=nutrition" class="home-feed-meal-delete-form" data-confirm-action="<?= e(t('nutrition.delete_meal_confirm')) ?>">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="delete_nutrition_entry">
                            <input type="hidden" name="entry_id" value="<?= $feedMealEntryId ?>">
                            <input type="hidden" name="return_date" value="<?= e($feedMealDate) ?>">
                            <input type="hidden" name="return_to" value="<?= e($feedReturnPath) ?>">
                            <button type="submit" aria-label="<?= e(t('nutrition.delete_meal')) ?>" title="<?= e(t('nutrition.delete_meal')) ?>"><?= activity_icon_svg('trash') ?><span class="sr-only"><?= e(t('nutrition.delete_meal')) ?></span></button>
                        </form>
                    <?php endif; ?>
                    <?php if ($feedType === 'workout' && !empty($feedItem['can_copy_workout'])): ?>
                        <?php if ((int) ($feedItem['copied_routine_id'] ?? 0) > 0): ?>
                            <a class="home-feed-copy-workout is-copied" href="/?page=workouts&amp;routine_id=<?= (int) $feedItem['copied_routine_id'] ?>" aria-label="<?= e(t('workouts.view_copied_routine')) ?>"><?= activity_icon_svg('check') ?><span class="home-feed-copy-workout-label"><strong><?= e(t('workouts.workout_copied')) ?></strong><small><?= e(t('workouts.view_copied_routine')) ?></small></span></a>
                        <?php else: ?>
                            <form method="post" action="/?page=dashboard" class="home-feed-copy-workout-form" data-feed-copy-workout-form data-confirm="<?= e(t('workouts.copy_workout_confirm', ['title' => $feedTitle])) ?>" data-allow-multi-submit>
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="social_feed_copy_workout">
                                <input type="hidden" name="entity_type" value="workout">
                                <input type="hidden" name="entity_id" value="<?= $feedId ?>">
                                <input type="hidden" name="feed_scope" value="<?= e($feedScope) ?>">
                                <button type="submit" class="home-feed-copy-workout" aria-label="<?= e(t('workouts.add_to_routine')) ?>" data-label-copy="<?= e(t('workouts.add_to_routine')) ?>" data-label-done="<?= e(t('workouts.workout_copied')) ?>" data-label-open="<?= e(t('workouts.view_copied_routine')) ?>"><?= activity_icon_svg('plus') ?><span class="home-feed-copy-workout-label"><strong><?= e(t('workouts.add_to_routine')) ?></strong><small><?= e(t('workouts.copy_workout')) ?></small></span></button>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                <div class="home-feed-comments"<?= $feedOpenComments === $feedType . '-' . $feedId ? '' : ' hidden' ?> data-feed-comments data-social-comment-region>
                    <?= social_comment_thread_html((array) ($feedItem['comments'] ?? []), $currentUser, (int) ($feedItem['user_id'] ?? 0), '/?page=dashboard', $feedType, $feedId, $feedScope) ?>
                    <form method="post" action="/?page=dashboard" class="social-comment-composer" data-social-comment-form data-allow-multi-submit><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="social_feed_comment"><input type="hidden" name="entity_type" value="<?= e($feedType) ?>"><input type="hidden" name="entity_id" value="<?= $feedId ?>"><input type="hidden" name="feed_scope" value="<?= e($feedScope) ?>"><label><span class="sr-only"><?= e(t('feed.comment_placeholder')) ?></span><input type="text" name="comment" maxlength="1200" required placeholder="<?= e(t('feed.comment_placeholder')) ?>"></label><button type="submit" aria-label="<?= e(t('feed.send')) ?>"><?= activity_icon_svg('send') ?></button></form>
                </div>
            </article>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
