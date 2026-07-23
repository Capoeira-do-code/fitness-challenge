<?php

declare(strict_types=1);

$challengeSummary = is_array($challengeCurrentSummary ?? null) ? (array) $challengeCurrentSummary : [];
$challengeArchiveRows = is_array($challengeArchives ?? null) ? (array) $challengeArchives : [];
$challengeBackupRows = is_array($systemBackups ?? null) ? (array) $systemBackups : [];
$latestChallengeBackup = null;
foreach ($challengeBackupRows as $candidateBackup) {
    if ((int) ($candidateBackup['file_exists'] ?? 0) === 1 && in_array((string) ($candidateBackup['status'] ?? ''), ['verified', 'restored'], true)) {
        $latestChallengeBackup = $candidateBackup;
        break;
    }
}
$todayKey = to_date(null);
$challengeState = !$challengeIsActive ? 'paused' : ($todayKey < $challengeStart ? 'upcoming' : ($todayKey > $challengeEnd ? 'finished' : 'active'));
$challengeTotalDays = max(1, (int) ($challengeSummary['days'] ?? 1));
$challengeElapsedDays = 0;
try {
    $startDateObject = new DateTimeImmutable($challengeStart);
    $endDateObject = new DateTimeImmutable($challengeEnd);
    $todayObject = new DateTimeImmutable($todayKey);
    if ($todayObject >= $startDateObject) {
        $boundedToday = $todayObject > $endDateObject ? $endDateObject : $todayObject;
        $challengeElapsedDays = min($challengeTotalDays, (int) $startDateObject->diff($boundedToday)->days + 1);
    }
} catch (Throwable) {
    $challengeElapsedDays = 0;
}
$challengeProgress = $challengeTotalDays > 0 ? round(($challengeElapsedDays / $challengeTotalDays) * 100, 1) : 0;
$formatChallengeCount = static fn(int|float $value, int $decimals = 0): string => number_format((float) $value, $decimals, ',', '.');
?>
<article class="panel settings-panel active admin-challenge-page" data-spa-section="challenge">
    <section class="admin-challenge-overview">
        <div class="admin-challenge-overview-main">
            <span class="admin-challenge-overview-icon" aria-hidden="true"><?= activity_icon_svg('target') ?></span>
            <div class="admin-challenge-overview-copy">
                <p class="eyebrow"><?= e(t('admin.challenge')) ?></p>
                <h2><?= e($challengeName) ?></h2>
                <p><?= e($challengeRangeLabel) ?> · <?= $challengeTotalDays ?> <?= e(t('admin.challenge_days')) ?></p>
            </div>
            <span class="admin-challenge-state is-<?= e($challengeState) ?>"><?= e(t('admin.challenge_state_' . $challengeState)) ?></span>
            <?php $renderAdminBack('/?page=admin&group=system', t('admin.group_system')); ?>
        </div>
        <div class="admin-challenge-progress" aria-label="<?= e(t('admin.challenge_time_progress')) ?>">
            <span style="--challenge-progress: <?= e((string) $challengeProgress) ?>%"></span>
            <small><?= e(t('admin.challenge_time_progress_value', ['current' => $challengeElapsedDays, 'total' => $challengeTotalDays, 'percent' => $challengeProgress])) ?></small>
        </div>
        <div class="admin-challenge-kpis">
            <span><strong><?= e($formatChallengeCount((int) ($challengeSummary['members'] ?? 0))) ?></strong><small><?= e(t('admin.challenge_participants')) ?></small></span>
            <span><strong><?= e($formatChallengeCount((int) ($challengeSummary['entries'] ?? 0))) ?></strong><small><?= e(t('admin.challenge_entries')) ?></small></span>
            <span><strong><?= e($formatChallengeCount((int) ($challengeSummary['steps'] ?? 0))) ?></strong><small><?= e(t('metric.steps')) ?></small></span>
            <span><strong><?= e($formatChallengeCount((float) ($challengeSummary['distance_km'] ?? 0), 1)) ?></strong><small>km</small></span>
            <span><strong><?= e($formatChallengeCount((int) ($challengeSummary['workouts'] ?? 0))) ?></strong><small><?= e(t('metric.workouts')) ?></small></span>
        </div>
    </section>

    <div class="admin-challenge-actions-grid">
        <details class="admin-challenge-action-card" open>
            <summary><span aria-hidden="true"><?= activity_icon_svg('sliders') ?></span><span><strong><?= e(t('admin.challenge_edit_title')) ?></strong><small><?= e(t('admin.challenge_edit_hint')) ?></small></span><b aria-hidden="true">⌄</b></summary>
            <form method="post" action="/?page=admin" class="admin-challenge-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="update_challenge_settings">
                <label class="admin-challenge-name-field"><span><?= e(t('admin.challenge_name')) ?></span><input type="text" name="challenge_name" value="<?= e($challengeName) ?>" maxlength="100" required></label>
                <label><span><?= e(t('audit.from')) ?></span><input type="date" name="challenge_start" value="<?= e($challengeStart) ?>" required></label>
                <label><span><?= e(t('audit.to')) ?></span><input type="date" name="challenge_end" value="<?= e($challengeEnd) ?>" required></label>
                <label class="admin-challenge-backup-check"><input type="checkbox" name="backup_before_update" value="1"><span><strong><?= e(t('admin.challenge_backup_before_change')) ?></strong><small><?= e(t('admin.challenge_backup_optional_edit_hint')) ?></small></span></label>
                <button class="btn btn-primary" type="submit"><?= e(t('common.save')) ?></button>
            </form>
        </details>

        <details class="admin-challenge-action-card admin-challenge-new-card">
            <summary><span aria-hidden="true"><?= activity_icon_svg('plus') ?></span><span><strong><?= e(t('admin.start_new_challenge')) ?></strong><small><?= e(t('admin.challenge_new_hint')) ?></small></span><b aria-hidden="true">⌄</b></summary>
            <form method="post" action="/?page=admin" class="admin-challenge-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="start_new_challenge">
                <label class="admin-challenge-name-field"><span><?= e(t('admin.new_challenge_name')) ?></span><input type="text" name="new_challenge_name" placeholder="<?= e($challengeName) ?>" maxlength="100" required></label>
                <label><span><?= e(t('audit.from')) ?></span><input type="date" name="new_challenge_start" value="<?= e($nextChallengeStart) ?>" required></label>
                <label><span><?= e(t('audit.to')) ?></span><input type="date" name="new_challenge_end" value="<?= e($nextChallengeEnd) ?>" required></label>
                <label class="admin-challenge-backup-check"><input type="checkbox" name="backup_before_start" value="1" checked><span><strong><?= e(t('admin.challenge_backup_before_change')) ?></strong><small><?= e(t('admin.challenge_backup_before_change_hint')) ?></small></span></label>
                <button class="btn btn-secondary" type="submit"><?= e(t('admin.start_new_challenge')) ?></button>
            </form>
        </details>
    </div>

    <section class="admin-challenge-history">
        <div class="admin-challenge-section-head">
            <div><p class="eyebrow"><?= e(t('admin.challenge_history_eyebrow')) ?></p><h3><?= e(t('admin.archived_challenges')) ?></h3><p><?= e(t('admin.challenge_history_hint')) ?></p></div>
            <span><strong><?= count($challengeArchiveRows) ?></strong><small><?= e(t('admin.challenge_saved_periods')) ?></small></span>
        </div>
        <?php if ($challengeArchiveRows !== []): ?>
            <div class="admin-challenge-history-list">
                <?php foreach ($challengeArchiveRows as $archive): ?>
                    <?php
                    $archiveId = (int) ($archive['id'] ?? 0);
                    $archiveStart = to_date((string) ($archive['challenge_start'] ?? ''));
                    $archiveEnd = to_date((string) ($archive['challenge_end'] ?? ''), $archiveStart);
                    $archiveSummary = is_array($archive['summary'] ?? null) ? (array) $archive['summary'] : [];
                    $archiveIsCurrent = $challengeIsActive
                        && $challengeName === (string) ($archive['challenge_name'] ?? '')
                        && $challengeStart === $archiveStart
                        && $challengeEnd === $archiveEnd;
                    $archiveRestoredCount = (int) ($archive['restore_count'] ?? 0);
                    ?>
                    <article class="admin-challenge-history-item<?= $archiveIsCurrent ? ' is-current' : '' ?>">
                        <div class="admin-challenge-history-item-head">
                            <span class="admin-challenge-history-icon" aria-hidden="true"><?= activity_icon_svg($archiveIsCurrent ? 'check' : 'target') ?></span>
                            <div><strong><?= e((string) ($archive['challenge_name'] ?? '')) ?></strong><small><?= e(format_date_eu($archiveStart)) ?> – <?= e(format_date_eu($archiveEnd)) ?> · <?= (int) ($archiveSummary['days'] ?? 1) ?> <?= e(t('admin.challenge_days')) ?></small></div>
                            <?php if ($archiveIsCurrent): ?><b><?= e(t('admin.challenge_state_active')) ?></b><?php endif; ?>
                        </div>
                        <div class="admin-challenge-history-stats">
                            <span><strong><?= e($formatChallengeCount((int) ($archiveSummary['members'] ?? 0))) ?></strong><small><?= e(t('admin.challenge_participants')) ?></small></span>
                            <span><strong><?= e($formatChallengeCount((int) ($archiveSummary['steps'] ?? 0))) ?></strong><small><?= e(t('metric.steps')) ?></small></span>
                            <span><strong><?= e($formatChallengeCount((float) ($archiveSummary['distance_km'] ?? 0), 1)) ?></strong><small>km</small></span>
                            <span><strong><?= e($formatChallengeCount((int) ($archiveSummary['workouts'] ?? 0))) ?></strong><small><?= e(t('metric.workouts')) ?></small></span>
                        </div>
                        <p class="admin-challenge-history-note">
                            <?= e(t('admin.archived_on', ['date' => $formatRuntimeDate((string) ($archive['archived_at'] ?? ''))])) ?>
                            <?php if (trim((string) ($archive['archived_by_name'] ?? '')) !== ''): ?> · <?= e((string) $archive['archived_by_name']) ?><?php endif; ?>
                            <?php if ($archiveRestoredCount > 0): ?> · <?= e(t('admin.challenge_restored_count', ['count' => $archiveRestoredCount])) ?><?php endif; ?>
                        </p>
                        <div class="admin-challenge-history-actions">
                            <a class="btn btn-ghost small" href="/?<?= e(http_build_query(['page' => 'profile', 'challenge' => 'archive:' . $archiveId])) ?>"><?= e(t('profile.open_challenge')) ?></a>
                            <?php if (!$archiveIsCurrent): ?>
                                <details class="admin-challenge-restore">
                                    <summary class="btn btn-secondary small"><?= e(t('admin.challenge_restore_action')) ?></summary>
                                    <form method="post" action="/?page=admin">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="reactivate_challenge"><input type="hidden" name="archive_id" value="<?= $archiveId ?>">
                                        <strong><?= e(t('admin.challenge_restore_title')) ?></strong>
                                        <p><?= e(t('admin.challenge_restore_hint', ['name' => (string) ($archive['challenge_name'] ?? '')])) ?></p>
                                        <label class="admin-challenge-backup-check"><input type="checkbox" name="backup_before_restore" value="1" checked><span><strong><?= e(t('admin.challenge_backup_before_change')) ?></strong><small><?= e(t('admin.challenge_backup_before_change_hint')) ?></small></span></label>
                                        <label><span><?= e(t('admin.challenge_restore_confirm')) ?></span><input type="text" name="confirm_restore" placeholder="RESTORE" autocomplete="off" required></label>
                                        <button class="btn btn-primary" type="submit"><?= e(t('admin.challenge_restore_action')) ?></button>
                                    </form>
                                </details>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="admin-challenge-empty"><span aria-hidden="true"><?= activity_icon_svg('list') ?></span><strong><?= e(t('admin.challenge_history_empty_title')) ?></strong><p><?= e(t('admin.challenge_history_empty_hint')) ?></p></div>
        <?php endif; ?>
    </section>

    <section class="admin-challenge-safety">
        <div class="admin-challenge-safety-copy">
            <span aria-hidden="true"><?= activity_icon_svg('shield') ?></span>
            <div><p class="eyebrow"><?= e(t('admin.challenge_safety_eyebrow')) ?></p><h3><?= e(t('admin.challenge_safety_title')) ?></h3><p><?= e(t('admin.challenge_safety_hint')) ?></p></div>
        </div>
        <div class="admin-challenge-backup-status">
            <small><?= e(t('admin.backup_last_valid')) ?></small>
            <strong><?= e($latestChallengeBackup !== null ? $formatRuntimeDate((string) ($latestChallengeBackup['created_at'] ?? '')) : t('admin.backup_last_auto_never')) ?></strong>
            <span><?= e($latestChallengeBackup !== null ? (string) ($latestChallengeBackup['size_label'] ?? '0 B') : t('admin.challenge_no_backup')) ?></span>
        </div>
        <div class="admin-challenge-safety-actions">
            <form method="post" action="/?page=admin"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="create_challenge_backup"><button class="btn btn-secondary" type="submit"><?= e(t('admin.backup_create_now')) ?></button></form>
            <a class="btn btn-ghost" href="/?page=admin&section=backups"><?= e(t('admin.challenge_manage_backups')) ?></a>
        </div>
    </section>

    <details class="admin-challenge-pause">
        <summary><span><?= e(t('admin.challenge_pause_title')) ?></span><small><?= e(t('admin.challenge_archive_hint')) ?></small><b aria-hidden="true">⌄</b></summary>
        <form method="post" action="/?page=admin">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="archive_challenge">
            <label class="admin-challenge-backup-check"><input type="checkbox" name="backup_before_archive" value="1" checked><span><strong><?= e(t('admin.challenge_backup_before_change')) ?></strong><small><?= e(t('admin.challenge_backup_before_change_hint')) ?></small></span></label>
            <label><span><?= e(t('admin.archive_confirm')) ?></span><input type="text" name="confirm_archive" placeholder="ARCHIVE" autocomplete="off" required></label>
            <button class="btn btn-ghost" type="submit" <?= !$challengeIsActive ? 'disabled' : '' ?>><?= e(t('admin.archive_challenge')) ?></button>
        </form>
    </details>
</article>
