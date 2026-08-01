<?php
$session = is_array($sharedSession ?? null) ? $sharedSession : null;
$exercises = array_values((array) ($sharedExercises ?? []));
$volume = 0.0;
$sets = 0;
foreach ($exercises as $exercise) {
    foreach ((array) ($exercise['sets'] ?? []) as $set) {
        if ((int) ($set['completed'] ?? 0) !== 1) continue;
        $volume += (float) ($set['weight'] ?? 0) * (int) ($set['reps'] ?? 0);
        $sets++;
    }
}
$title = $session !== null ? (wk_session_display_title($session) ?: t('workouts.session')) : '';
$owner = trim((string) ($session['display_name'] ?? $session['username'] ?? ''));
$formatWeight = static fn(float $value): string => number_format($value, abs($value - round($value)) < .001 ? 0 : 1, ',', '.') . ' kg';
?>
<section class="shared-workout-page">
    <?php if ($session === null): ?>
        <article class="panel shared-workout-empty"><h1><?= e(t('workouts.shared_not_found')) ?></h1><p><?= e(t('workouts.shared_not_found_hint')) ?></p><a class="btn btn-primary" href="/?page=login"><?= e(t('login.submit')) ?></a></article>
    <?php else: ?>
        <header class="shared-workout-brand"><a href="/"><?= e((string) ($config['app_name'] ?? 'Fitness Challenge')) ?></a><span><?= e(t('workouts.shared_public')) ?></span></header>
        <article class="shared-workout-hero">
            <span class="shared-workout-hero-icon" aria-hidden="true"><?= activity_icon_svg(wk_normalize_routine_icon((string) ($session['routine_icon'] ?? 'dumbbell'))) ?></span>
            <p class="eyebrow"><?= e(t('workouts.shared_by', ['name' => $owner])) ?></p>
            <h1><?= e($title) ?></h1>
            <p><?= e(format_date_eu((string) ($session['started_at'] ?? ''))) ?></p>
            <div class="shared-workout-metrics"><span><strong><?= e($formatWeight($volume)) ?></strong><small><?= e(t('workouts.stat_volume')) ?></small></span><span><strong><?= $sets ?></strong><small><?= e(t('workouts.stat_sets')) ?></small></span><span><strong><?= count($exercises) ?></strong><small><?= e(t('workouts.exercises')) ?></small></span></div>
        </article>
        <div class="shared-workout-exercises">
            <?php foreach ($exercises as $exercise): ?>
                <article class="panel"><h2><?= e((string) ($exercise['exercise_name'] ?? '')) ?></h2><ul>
                    <?php foreach ((array) ($exercise['sets'] ?? []) as $set): if ((int) ($set['completed'] ?? 0) !== 1) continue; ?>
                        <li><span><?= e($formatWeight((float) ($set['weight'] ?? 0))) ?></span><strong>&times; <?= (int) ($set['reps'] ?? 0) ?></strong></li>
                    <?php endforeach; ?>
                </ul></article>
            <?php endforeach; ?>
        </div>
        <footer class="shared-workout-cta"><strong><?= e(t('workouts.shared_cta_title')) ?></strong><p><?= e(t('workouts.shared_cta_hint')) ?></p><a class="btn btn-primary" href="/?page=login"><?= e(t('workouts.shared_cta')) ?></a></footer>
    <?php endif; ?>
</section>
