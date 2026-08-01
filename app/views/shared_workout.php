<?php
$session = is_array($sharedSession ?? null) ? $sharedSession : null;
$exercises = wk_session_completed_exercises((array) ($sharedExercises ?? []));
$volume = 0.0;
$sets = 0;
$muscles = [];
foreach ($exercises as $exercise) {
    foreach ((array) ($exercise['sets'] ?? []) as $set) {
        if ((int) ($set['completed'] ?? 0) !== 1) continue;
        $volume += (float) ($set['weight'] ?? 0) * (int) ($set['reps'] ?? 0);
        $sets++;
        $muscle = trim((string) ($exercise['muscle_group'] ?? ''));
        if ($muscle !== '') $muscles[$muscle] = ($muscles[$muscle] ?? 0) + 1;
    }
}
arsort($muscles);
$title = $session !== null ? (wk_session_display_title($session) ?: t('workouts.session')) : '';
$owner = trim((string) ($session['display_name'] ?? $session['username'] ?? ''));
$started = $session !== null ? (strtotime((string) ($session['started_at'] ?? '')) ?: 0) : 0;
$ended = $session !== null ? (strtotime((string) ($session['ended_at'] ?? '')) ?: $started) : 0;
$minutes = max(0, (int) round(($ended - $started) / 60));
$duration = $minutes >= 60 ? intdiv($minutes, 60) . 'h ' . ($minutes % 60) . 'min' : $minutes . 'min';
$formatWeight = static fn(float $value): string => number_format($value, abs($value - round($value)) < .001 ? 0 : 1, ',', '.') . ' kg';
$muscleLabel = static fn(string $muscle): string => $muscle !== '' ? t('workouts.muscle_' . $muscle) : '';
$equipmentLabel = static fn(string $equipment): string => $equipment !== '' ? t('workouts.equipment_' . $equipment) : '';
$videoSource = static fn(?string $url): ?array => wk_exercise_video_source($url);
$exerciseMedia = is_array($sharedExerciseMedia ?? null) ? $sharedExerciseMedia : [];
$sharedRoutines = array_values((array) ($sharedUserRoutines ?? []));
$sharedViewerId = (int) ($currentUser['id'] ?? 0);
$sharedIsOtherWorkout = $session !== null && (int) ($session['user_id'] ?? 0) !== $sharedViewerId;
$token = trim((string) ($_GET['token'] ?? ''));
$mediaUrl = static fn(string $path, int $width = 900): string => '/?page=shared_workout_media&token=' . rawurlencode($token) . '&path=' . rawurlencode($path) . '&w=' . max(80, min(1200, $width));
?>
<section class="shared-workout-page">
<?php if ($session === null): ?>
    <article class="panel shared-workout-empty"><h1><?= e(t('workouts.shared_not_found')) ?></h1><p><?= e(t('workouts.shared_not_found_hint')) ?></p><a class="btn btn-primary" href="/?page=login"><?= e(t('login.submit')) ?></a></article>
<?php else: ?>
    <header class="shared-workout-topbar"><a href="/"><span aria-hidden="true">&larr;</span><strong><?= e((string) ($config['app_name'] ?? 'Fitness Challenge')) ?></strong></a><span><?= e(t('workouts.shared_public')) ?></span></header>
    <main class="shared-workout-detail">
        <article class="shared-workout-summary">
            <header class="shared-workout-author"><span><?= e(initials_for($owner)) ?></span><div><strong><?= e($owner) ?></strong><p><?= e(format_date_eu((string) ($session['started_at'] ?? ''))) ?><?= $started > 0 ? ' · ' . e(date('H:i', $started)) : '' ?></p></div></header>
            <h1><?= e($title) ?></h1>
            <div class="shared-workout-metrics"><span><small><?= e(t('feed.time')) ?></small><strong><?= e($duration) ?></strong></span><span><small><?= e(t('workouts.stat_volume')) ?></small><strong><?= e($formatWeight($volume)) ?></strong></span><span><small><?= e(t('workouts.stat_sets')) ?></small><strong><?= $sets ?></strong></span><span><small><?= e(t('workouts.exercises')) ?></small><strong><?= count($exercises) ?></strong></span></div>
            <button class="shared-workout-share" type="button" data-workout-native-share data-share-url="<?= e(rtrim(request_app_base_url(), '/') . '/?page=shared_workout&token=' . rawurlencode($token)) ?>" data-share-title="<?= e($title) ?>" data-share-text="<?= e(t('workouts.share_native_text', ['title' => $title, 'volume' => $formatWeight($volume)])) ?>"><?= activity_icon_svg('share') ?><span><?= e(t('feed.share')) ?></span></button>
        </article>
        <?php if ($muscles !== []): ?><section class="shared-workout-muscles"><h2><?= e(t('workouts.muscle_split')) ?></h2><?php foreach ($muscles as $muscle => $muscleSets): $pct = $sets > 0 ? (int) round($muscleSets / $sets * 100) : 0; ?><div><strong><?= e($muscleLabel($muscle)) ?></strong><span><i style="width:<?= $pct ?>%"></i></span><em><?= $pct ?>%</em></div><?php endforeach; ?></section><?php endif; ?>
        <section class="shared-workout-list" aria-label="<?= e(t('workouts.exercises')) ?>">
        <?php foreach ($exercises as $exercise):
            $imagePath = trim((string) ($exercise['image_path'] ?? ''));
            $sessionExerciseId = (int) ($exercise['id'] ?? 0);
            $exerciseDefId = (int) ($exercise['exercise_def_id'] ?? 0);
            $modalId = 'shared-workout-exercise-detail-' . $sessionExerciseId;
            $modalTitleId = $modalId . '-title';
            $gallery = array_values((array) ($exerciseMedia[$exerciseDefId] ?? []));
            $content = (array) ($exercise['content'] ?? []);
            $video = $videoSource((string) ($exercise['video_url'] ?? ''));
            $secondaryDecoded = json_decode((string) ($exercise['secondary_muscles'] ?? '[]'), true);
            $secondaryMuscles = array_values(array_filter(array_map('strval', is_array($secondaryDecoded) ? $secondaryDecoded : [])));
        ?>
            <article class="shared-workout-exercise">
                <button class="shared-workout-exercise-trigger" type="button" data-app-modal-open="<?= e($modalId) ?>" data-workout-exercise-detail-open="<?= e($modalId) ?>" aria-haspopup="dialog" aria-controls="<?= e($modalId) ?>" aria-label="<?= e((string) ($exercise['exercise_name'] ?? '')) ?>">
                    <?php if ($imagePath !== ''): ?><img src="<?= e($mediaUrl($imagePath, 160)) ?>" alt="" loading="lazy"><?php else: ?><span class="shared-workout-exercise-icon" aria-hidden="true"><?= activity_icon_svg('dumbbell') ?></span><?php endif; ?>
                    <span><strong><?= e((string) ($exercise['exercise_name'] ?? '')) ?></strong><small><?= e($muscleLabel((string) ($exercise['muscle_group'] ?? ''))) ?></small></span>
                    <b aria-hidden="true">&rsaquo;</b>
                </button>
                <div class="shared-workout-set-head"><span><?= e(t('workouts.sets')) ?></span><span><?= e(t('workouts.weight')) ?> &amp; <?= e(t('workouts.reps')) ?></span></div>
                <ol><?php foreach ((array) ($exercise['sets'] ?? []) as $set): if ((int) ($set['completed'] ?? 0) !== 1) continue; ?><li><span><?= (int) ($set['set_index'] ?? 0) ?></span><strong><?= e($formatWeight((float) ($set['weight'] ?? 0))) ?> &times; <?= (int) ($set['reps'] ?? 0) ?></strong></li><?php endforeach; ?></ol>
            </article>
            <div class="app-modal shared-workout-exercise-modal" id="<?= e($modalId) ?>" hidden role="dialog" aria-modal="true" aria-labelledby="<?= e($modalTitleId) ?>">
                <div class="app-modal-card shared-workout-exercise-sheet">
                    <div class="shared-workout-exercise-grabber" aria-hidden="true"></div>
                    <div class="shared-workout-exercise-modal-head"><h2 id="<?= e($modalTitleId) ?>"><?= e((string) ($exercise['exercise_name'] ?? '')) ?></h2><button type="button" data-app-modal-close aria-label="<?= e(t('common.close_action')) ?>">&times;</button></div>
                    <?php if ($gallery !== []): ?>
                        <div class="shared-workout-exercise-gallery">
                            <?php foreach ($gallery as $galleryIndex => $galleryItem):
                                $galleryPath = trim((string) ($galleryItem['path'] ?? ''));
                                if ($galleryPath === '') continue;
                            ?><figure<?= $galleryIndex === 0 ? ' class="is-primary"' : '' ?>><img src="<?= e($mediaUrl($galleryPath, $galleryIndex === 0 ? 900 : 320)) ?>" alt="<?= e((string) ($galleryItem['caption'] ?? '')) ?>" loading="lazy" style="object-position:<?= e((string) ($galleryItem['position'] ?? 'center')) ?>"><?php if (trim((string) ($galleryItem['caption'] ?? '')) !== ''): ?><figcaption><?= e((string) $galleryItem['caption']) ?></figcaption><?php endif; ?></figure><?php endforeach; ?>
                        </div>
                    <?php elseif ($imagePath !== ''): ?><img class="shared-workout-exercise-cover" src="<?= e($mediaUrl($imagePath, 900)) ?>" alt="<?= e((string) ($exercise['exercise_name'] ?? '')) ?>" loading="lazy"><?php endif; ?>
                    <dl class="shared-workout-exercise-meta">
                        <div><dt><?= e(t('workouts.primary_muscle')) ?></dt><dd><?= e($muscleLabel((string) ($exercise['muscle_group'] ?? ''))) ?></dd></div>
                        <?php if ($secondaryMuscles !== []): ?><div><dt><?= e(t('workouts.secondary_muscles')) ?></dt><dd><?= e(implode(', ', array_map($muscleLabel, $secondaryMuscles))) ?></dd></div><?php endif; ?>
                        <div><dt><?= e(t('workouts.equipment')) ?></dt><dd><?= e($equipmentLabel((string) ($exercise['equipment'] ?? ''))) ?></dd></div>
                    </dl>
                    <?php if ($sharedIsOtherWorkout): ?><div class="shared-workout-exercise-actions">
                        <?php if ($sharedViewerId <= 0): ?>
                            <a class="btn btn-primary" href="/?page=login"><?= activity_icon_svg('plus') ?><span><?= e(t('workouts.add_to_routine')) ?></span></a>
                        <?php elseif ($sharedRoutines !== []): ?>
                            <button class="btn btn-primary" type="button" data-app-modal-open="shared-workout-add-routine-modal" data-workout-routine-picker-open data-exercise-id="<?= $exerciseDefId ?>" data-exercise-name="<?= e((string) ($exercise['exercise_name'] ?? '')) ?>"><?= activity_icon_svg('plus') ?><span><?= e(t('workouts.add_to_routine')) ?></span></button>
                        <?php else: ?>
                            <a class="btn btn-primary" href="/?page=workouts"><?= activity_icon_svg('plus') ?><span><?= e(t('workouts.add_to_routine')) ?></span></a>
                        <?php endif; ?>
                    </div><?php endif; ?>
                    <?php if ($video !== null): ?><div class="shared-workout-exercise-video"><?php if (($video['type'] ?? '') === 'iframe'): ?><iframe src="<?= e((string) ($video['url'] ?? '')) ?>" title="<?= e((string) ($exercise['exercise_name'] ?? '')) ?>" loading="lazy" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen></iframe><?php elseif (($video['type'] ?? '') === 'video'): ?><video src="<?= e((string) ($video['url'] ?? '')) ?>" controls preload="metadata"></video><?php else: ?><a class="btn btn-ghost" href="<?= e((string) ($video['url'] ?? '')) ?>" target="_blank" rel="noopener noreferrer"><?= e(t('workouts.watch_video')) ?> &nearr;</a><?php endif; ?></div><?php endif; ?>
                    <?php if ((array) ($content['steps'] ?? []) !== []): ?><section class="shared-workout-exercise-how"><h3><?= e(t('workouts.how_to')) ?></h3><ol><?php foreach ((array) $content['steps'] as $step): ?><li><?= e((string) $step) ?></li><?php endforeach; ?></ol></section><?php endif; ?>
                    <button class="btn btn-primary shared-workout-exercise-done" type="button" data-app-modal-close><?= e(t('workouts.done')) ?></button>
                </div>
            </div>
        <?php endforeach; ?>
        </section>
        <?php if ($sharedViewerId > 0 && $sharedIsOtherWorkout && $sharedRoutines !== []): ?>
        <div class="app-modal shared-workout-routine-modal" id="shared-workout-add-routine-modal" hidden role="dialog" aria-modal="true" aria-labelledby="shared-workout-add-routine-title" data-workout-routine-picker data-single-routine="<?= count($sharedRoutines) === 1 ? '1' : '0' ?>">
            <div class="app-modal-card shared-workout-routine-card">
                <div class="app-modal-head"><div><p class="eyebrow"><?= e(t('workouts.exercises')) ?></p><h2 id="shared-workout-add-routine-title"><?= e(t('workouts.add_to_routine')) ?></h2><p class="muted" data-workout-routine-picker-copy><?= e(t('workouts.choose_routine')) ?></p></div><button type="button" class="app-modal-close" data-app-modal-close aria-label="<?= e(t('common.close_action')) ?>">&times;</button></div>
                <form method="post" action="/?page=shared_workout&amp;token=<?= e(rawurlencode($token)) ?>" data-workout-routine-picker-form>
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="shared_workout_add_exercise">
                    <input type="hidden" name="exercise_def_id" value="" data-workout-routine-picker-exercise>
                    <fieldset class="shared-workout-routine-list"><legend class="sr-only"><?= e(t('workouts.choose_routine')) ?></legend><?php foreach ($sharedRoutines as $routine): ?><label><input type="radio" name="routine_id" value="<?= (int) ($routine['id'] ?? 0) ?>" required<?= count($sharedRoutines) === 1 ? ' checked' : '' ?>><span aria-hidden="true"><?= activity_icon_svg(wk_normalize_routine_icon($routine['icon'] ?? 'dumbbell')) ?></span><span><strong><?= e((string) ($routine['name'] ?? '')) ?></strong><small><?php $sharedRoutineExerciseCount = (int) ($routine['exercise_count'] ?? 0); ?><?= e(t($sharedRoutineExerciseCount === 1 ? 'workouts.exercise_count_one' : 'workouts.exercise_count', ['count' => $sharedRoutineExerciseCount])) ?></small></span><b aria-hidden="true"><?= activity_icon_svg('check') ?></b></label><?php endforeach; ?></fieldset>
                    <div class="shared-workout-routine-actions"><button class="btn btn-ghost" type="button" data-app-modal-close><?= e(t('common.cancel')) ?></button><button class="btn btn-primary" type="submit" data-workout-routine-picker-submit<?= count($sharedRoutines) > 1 ? ' disabled' : '' ?>><?= e(t('workouts.add_to_routine')) ?></button></div>
                </form>
            </div>
        </div>
        <?php endif; ?>
        <?php
        // Open the app on the workout itself. The owner lands straight on this
        // session; anyone else (or a logged-out visitor) goes to the workouts
        // hub, which funnels through login when needed.
        $sharedOpenUrl = (!$sharedIsOtherWorkout && $sharedViewerId > 0 && (int) ($session['id'] ?? 0) > 0)
            ? '/?page=workouts&amp;session_id=' . (int) $session['id']
            : '/?page=workouts';
        ?>
        <footer class="shared-workout-cta"><strong><?= e(t('workouts.shared_cta_title')) ?></strong><p><?= e(t('workouts.shared_cta_hint')) ?></p><a class="btn btn-primary" href="<?= $sharedOpenUrl ?>"><?= e(t('workouts.shared_cta')) ?></a></footer>
    </main>
<?php endif; ?>
</section>
