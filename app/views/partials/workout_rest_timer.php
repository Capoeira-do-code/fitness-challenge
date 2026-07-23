<?php

declare(strict_types=1);

$restTimerSessionId = max(0, (int) ($workoutRestTimerSessionId ?? 0));
$restTimerDefaultSeconds = max(0, min(3600, (int) ($workoutRestTimerDefaultSeconds ?? 0)));
$restTimerMinutes = intdiv($restTimerDefaultSeconds, 60);
$restTimerSeconds = $restTimerDefaultSeconds % 60;
$restTimerClock = sprintf('%02d:%02d', $restTimerMinutes, $restTimerSeconds);
?>
<section
    class="workouts-rest-timer"
    data-workout-rest-timer
    data-session-id="<?= $restTimerSessionId ?>"
    data-default-seconds="<?= $restTimerDefaultSeconds ?>"
    data-ready-label="<?= e(t('workouts.rest_ready')) ?>"
    data-ready-hint="<?= e(t('workouts.rest_ready_hint')) ?>"
    data-running-label="<?= e(t('workouts.rest_running')) ?>"
    data-paused-label="<?= e(t('workouts.rest_paused')) ?>"
    data-complete-label="<?= e(t('workouts.rest_complete')) ?>"
    data-start-label="<?= e(t('workouts.rest_start')) ?>"
    data-pause-label="<?= e(t('workouts.rest_pause')) ?>"
    data-resume-label="<?= e(t('workouts.rest_resume')) ?>"
    data-restart-label="<?= e(t('workouts.rest_restart')) ?>"
    data-skip-label="<?= e(t('workouts.rest_skip')) ?>"
    data-close-label="<?= e(t('common.close')) ?>"
    data-state="idle"<?= $restTimerDefaultSeconds <= 0 ? ' hidden' : '' ?>
>
    <div class="workouts-rest-timer-clock" data-rest-timer-clock style="--rest-progress: 100%">
        <time datetime="PT<?= $restTimerDefaultSeconds ?>S" data-rest-timer-time role="timer"><?= e($restTimerClock) ?></time>
    </div>
    <div class="workouts-rest-timer-copy">
        <span class="eyebrow"><?= e(t('workouts.rest_timer')) ?></span>
        <strong data-rest-timer-title><?= e(t('workouts.rest_ready')) ?></strong>
        <small data-rest-timer-status aria-live="polite"><?= e(t('workouts.rest_ready_hint')) ?></small>
    </div>
    <button class="btn btn-primary workouts-rest-timer-toggle" type="button" data-rest-timer-toggle aria-label="<?= e(t('workouts.rest_start')) ?>">
        <span class="workouts-rest-timer-control-icon" aria-hidden="true" data-rest-timer-icon data-icon-state="play">
            <span data-rest-icon="play"><?= activity_icon_svg('play') ?></span>
            <span data-rest-icon="pause"><?= activity_icon_svg('pause') ?></span>
            <span data-rest-icon="restart"><?= activity_icon_svg('restart') ?></span>
        </span>
        <span data-rest-timer-toggle-label><?= e(t('workouts.rest_start')) ?></span>
    </button>
    <div class="workouts-rest-timer-actions">
        <button class="btn btn-ghost" type="button" data-rest-timer-adjust="-15" aria-label="<?= e(t('workouts.rest_reduce')) ?>">&minus;15</button>
        <button class="btn btn-ghost" type="button" data-rest-timer-adjust="15" aria-label="<?= e(t('workouts.rest_extend')) ?>">+15</button>
        <button class="btn btn-ghost" type="button" data-rest-timer-skip><?= e(t('workouts.rest_skip')) ?></button>
    </div>
</section>
