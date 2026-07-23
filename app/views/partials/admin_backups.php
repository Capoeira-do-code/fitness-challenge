<?php

declare(strict_types=1);

$backupRows = is_array($systemBackups ?? null) ? array_values((array) $systemBackups) : [];
$latestValidBackup = null;
$latestAvailableBackup = null;
$availableBackupCount = 0;
$verifiedBackupCount = 0;
$backupIssueCount = 0;
$backupTotalBytes = 0;
foreach ($backupRows as $candidateBackup) {
    $fileExists = (int) ($candidateBackup['file_exists'] ?? 0) === 1;
    $status = (string) ($candidateBackup['status'] ?? 'created');
    if ($fileExists) {
        $availableBackupCount++;
        $backupTotalBytes += max(0, (int) ($candidateBackup['size_bytes'] ?? 0));
        $latestAvailableBackup ??= $candidateBackup;
    }
    if ($fileExists && in_array($status, ['verified', 'restored'], true)) {
        $verifiedBackupCount++;
        $latestValidBackup ??= $candidateBackup;
    }
    if (!$fileExists || $status === 'error') {
        $backupIssueCount++;
    }
}
$backupHealthState = $backupLastError !== '' ? 'error' : ($latestValidBackup !== null ? 'healthy' : 'attention');
$backupHealthLabel = t('admin.backups_health_' . $backupHealthState);
$backupFrequencyLabel = t('admin.backup_frequency_' . $backupFrequency);
$backupScheduleSummary = $backupAutoEnabled
    ? t('admin.backups_schedule_summary', ['frequency' => $backupFrequencyLabel, 'time' => $backupRunTime])
    : t('admin.backup_disabled');
$backupFilterSearchValue = static function (array $backup) use ($backupTriggerLabel, $backupStatusLabel): string {
    $trigger = (string) ($backup['trigger_type'] ?? 'manual');
    $status = (string) ($backup['status'] ?? 'created');
    $value = trim(implode(' ', [
        basename((string) ($backup['file_path'] ?? '')),
        $trigger,
        $backupTriggerLabel($trigger),
        $status,
        $backupStatusLabel($status),
        (string) ($backup['created_at'] ?? ''),
    ]));
    return function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
};
?>

<article class="panel settings-panel active admin-backups-page" data-spa-section="backups">
    <section class="admin-backups-overview is-<?= e($backupHealthState) ?>">
        <div class="admin-backups-overview-head">
            <span class="admin-backups-overview-icon" aria-hidden="true"><?= activity_icon_svg('shield') ?></span>
            <div>
                <p class="eyebrow"><?= e(t('admin.section_backups')) ?></p>
                <h2><?= e(t('admin.backups_control_title')) ?></h2>
                <p><?= e(t('admin.backups_control_hint')) ?></p>
            </div>
            <div class="admin-backups-overview-actions">
                <form method="post" action="/?page=admin&amp;section=backups">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="create_backup_now">
                    <button class="btn btn-primary" type="submit"><?= activity_icon_svg('plus') ?><span><?= e(t('admin.backup_create_now')) ?></span></button>
                </form>
                <?php $renderAdminBack('/?page=admin&group=system', t('admin.group_system')); ?>
            </div>
        </div>
        <div class="admin-backups-health-line">
            <span aria-hidden="true"></span><strong><?= e($backupHealthLabel) ?></strong>
            <small><?= e($backupHealthState === 'healthy' ? t('admin.backups_health_healthy_hint') : ($backupHealthState === 'error' ? t('admin.backups_health_error_hint') : t('admin.backups_health_attention_hint'))) ?></small>
        </div>
        <div class="admin-backups-kpis">
            <span><small><?= e(t('admin.backup_last_valid')) ?></small><strong><?= e($latestValidBackup !== null ? $formatRuntimeDate((string) ($latestValidBackup['created_at'] ?? '')) : t('admin.backup_last_auto_never')) ?></strong><em><?= e($latestValidBackup !== null ? (string) ($latestValidBackup['size_label'] ?? '0 B') : t('admin.backups_verification_needed')) ?></em></span>
            <span><small><?= e(t('admin.backups_available')) ?></small><strong><?= $availableBackupCount ?></strong><em><?= e(t('admin.backups_verified_count', ['count' => $verifiedBackupCount])) ?></em></span>
            <span><small><?= e(t('admin.backups_disk_usage')) ?></small><strong><?= e(format_upload_size($backupTotalBytes)) ?></strong><em><?= e(t('admin.backups_retention_short', ['count' => $backupRetentionCount])) ?></em></span>
            <span><small><?= e(t('admin.backup_next_run')) ?></small><strong><?= e($backupAutoEnabled ? $formatRuntimeDate($backupNextRunAt) : t('admin.backup_disabled')) ?></strong><em><?= e($backupScheduleSummary) ?></em></span>
        </div>
    </section>

    <?php if ($backupLastError !== ''): ?>
        <div class="admin-backups-alert" role="alert"><span aria-hidden="true"><?= activity_icon_svg('shield') ?></span><div><strong><?= e(t('admin.backups_recent_error')) ?></strong><p><?= e($backupLastError) ?></p></div></div>
    <?php elseif ($latestValidBackup === null && $latestAvailableBackup !== null): ?>
        <div class="admin-backups-alert is-warning"><span aria-hidden="true"><?= activity_icon_svg('check') ?></span><div><strong><?= e(t('admin.backups_unverified_title')) ?></strong><p><?= e(t('admin.backups_unverified_hint')) ?></p></div></div>
    <?php endif; ?>

    <div class="admin-backups-layout">
        <details class="admin-backups-disclosure"<?= !$backupAutoEnabled ? ' open' : '' ?>>
            <summary>
                <span aria-hidden="true"><?= activity_icon_svg('bolt') ?></span>
                <span><strong><?= e(t('admin.backups_automation_title')) ?></strong><small><?= e($backupScheduleSummary) ?></small></span>
                <i class="is-<?= $backupAutoEnabled ? 'on' : 'off' ?>"><?= e($backupAutoEnabled ? t('common.active') : t('admin.backup_disabled')) ?></i>
                <b aria-hidden="true">⌄</b>
            </summary>
            <form method="post" action="/?page=admin&amp;section=backups" class="admin-backups-settings-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="update_backup_settings">
                <label class="admin-backups-switch-row"><span><strong><?= e(t('admin.backup_auto_enabled')) ?></strong><small><?= e(t('admin.backups_automation_hint')) ?></small></span><span class="admin-app-switch"><input type="checkbox" name="backup_auto_enabled" value="1" <?= $backupAutoEnabled ? 'checked' : '' ?>><i aria-hidden="true"></i></span></label>
                <div class="admin-backups-settings-grid">
                    <label><span><?= e(t('admin.backup_frequency')) ?></span><select name="backup_frequency"><option value="daily" <?= $backupFrequency === 'daily' ? 'selected' : '' ?>><?= e(t('admin.backup_frequency_daily')) ?></option><option value="weekly" <?= $backupFrequency === 'weekly' ? 'selected' : '' ?>><?= e(t('admin.backup_frequency_weekly')) ?></option><option value="monthly" <?= $backupFrequency === 'monthly' ? 'selected' : '' ?>><?= e(t('admin.backup_frequency_monthly')) ?></option></select></label>
                    <label><span><?= e(t('admin.backup_run_time')) ?></span><input type="time" name="backup_run_time" value="<?= e($backupRunTime) ?>" required></label>
                    <label><span><?= e(t('admin.backup_retention_count')) ?></span><input type="number" name="backup_retention_count" min="1" max="200" inputmode="numeric" value="<?= $backupRetentionCount ?>" required></label>
                </div>
                <div class="admin-backups-settings-footer"><small><?= e(t('admin.backup_last_auto', ['value' => $backupLastAutoLabel])) ?> · <?= e(t('admin.backup_last_drill')) ?>: <?= e($formatRuntimeDate($backupLastDrillAt)) ?></small><button class="btn btn-primary" type="submit"><?= e(t('common.save')) ?></button></div>
            </form>
        </details>

        <section class="admin-backups-history">
            <div class="admin-backups-section-head">
                <div><h3><?= e(t('admin.backups_history_title')) ?> <span><?= count($backupRows) ?></span></h3><p><?= e(t('admin.backups_history_hint')) ?></p></div>
                <div class="admin-backups-tools">
                    <label class="admin-backups-search"><span aria-hidden="true"><?= activity_icon_svg('search') ?></span><input type="search" autocomplete="off" placeholder="<?= e(t('admin.backups_search_placeholder')) ?>" data-backup-search></label>
                    <select aria-label="<?= e(t('common.filter')) ?>" data-backup-status-filter><option value="all"><?= e(t('admin.backups_filter_all')) ?></option><option value="verified"><?= e(t('admin.backups_filter_verified')) ?></option><option value="restored"><?= e(t('admin.backups_filter_restored')) ?></option><option value="issues"><?= e(t('admin.backups_filter_issues')) ?><?= $backupIssueCount > 0 ? ' (' . $backupIssueCount . ')' : '' ?></option></select>
                </div>
            </div>

            <div class="admin-backups-list" data-backup-list>
                <?php foreach ($backupRows as $backup): ?>
                    <?php
                    $backupId = (int) ($backup['id'] ?? 0);
                    if ($backupId <= 0) { continue; }
                    $filePath = trim((string) ($backup['file_path'] ?? ''));
                    $fileName = $filePath !== '' ? basename($filePath) : ('backup_' . $backupId . '.zip');
                    $createdAt = $formatRuntimeDate(trim((string) ($backup['created_at'] ?? '')));
                    $trigger = trim((string) ($backup['trigger_type'] ?? 'manual'));
                    $status = trim((string) ($backup['status'] ?? 'created'));
                    $exists = (int) ($backup['file_exists'] ?? 0) === 1;
                    $filterStatus = !$exists || $status === 'error' ? 'issues' : $status;
                    $sizeLabel = trim((string) ($backup['size_label'] ?? '0 B'));
                    $errorMessage = trim((string) ($backup['error_message'] ?? ''));
                    $canRestore = $exists && in_array($status, ['verified', 'restored'], true);
                    ?>
                    <article class="admin-backups-row<?= !$exists ? ' is-missing' : '' ?>" data-backup-row data-backup-status="<?= e($filterStatus) ?>" data-backup-search-value="<?= e($backupFilterSearchValue($backup)) ?>">
                        <span class="admin-backups-row-icon is-<?= e($filterStatus) ?>" aria-hidden="true"><?= activity_icon_svg($filterStatus === 'issues' ? 'shield' : 'check') ?></span>
                        <span class="admin-backups-row-copy"><strong><?= e(t('admin.backups_copy_number', ['id' => $backupId])) ?></strong><small title="<?= e($fileName) ?>"><?= e($createdAt) ?> · <?= e($backupTriggerLabel($trigger)) ?></small><?php if (!$exists): ?><em><?= e(t('admin.backup_missing_file')) ?></em><?php elseif ($errorMessage !== ''): ?><em><?= e($errorMessage) ?></em><?php endif; ?></span>
                        <span class="admin-backups-row-meta"><i class="is-<?= e($filterStatus) ?>"><?= e(!$exists ? t('admin.backups_status_missing') : $backupStatusLabel($status)) ?></i><b><?= e($sizeLabel !== '' ? $sizeLabel : '0 B') ?></b><small><?= e(t('admin.backups_complete_contents')) ?></small></span>
                        <div class="admin-backups-row-actions">
                            <form method="post" action="/?page=admin&amp;section=backups"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="download_backup"><input type="hidden" name="backup_id" value="<?= $backupId ?>"><button class="btn btn-ghost small" type="submit"<?= $exists ? '' : ' disabled' ?>><?= activity_icon_svg('download') ?><span><?= e(t('admin.backup_download')) ?></span></button></form>
                            <details class="admin-backups-row-options">
                                <summary aria-label="<?= e(t('admin.backups_options')) ?>" title="<?= e(t('admin.backups_options')) ?>"><span aria-hidden="true">•••</span></summary>
                                <div>
                                    <?php if ($exists && !in_array($status, ['verified', 'restored'], true)): ?>
                                        <form method="post" action="/?page=admin&amp;section=backups"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="verify_backup"><input type="hidden" name="backup_id" value="<?= $backupId ?>"><button class="btn btn-secondary small" type="submit"><?= activity_icon_svg('check') ?><?= e(t('admin.backups_verify')) ?></button></form>
                                    <?php endif; ?>
                                    <form method="post" action="/?page=admin&amp;section=backups" class="admin-backups-restore-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="restore_backup"><input type="hidden" name="backup_id" value="<?= $backupId ?>"><div><strong><?= e(t('admin.backups_restore_title')) ?></strong><small><?= e($canRestore ? t('admin.backups_restore_hint') : t('admin.backups_verify_before_restore')) ?></small></div><label><span><?= e(t('admin.backup_restore_confirm_label')) ?></span><input type="text" name="confirm_restore" placeholder="RESTORE" autocomplete="off" autocapitalize="characters" required<?= $canRestore ? '' : ' disabled' ?>></label><button class="btn btn-ghost small" type="submit" onclick="return window.confirm('<?= e(t('admin.backup_restore_confirm_dialog')) ?>');"<?= $canRestore ? '' : ' disabled' ?>><?= e(t('admin.backup_restore')) ?></button></form>
                                    <form method="post" action="/?page=admin&amp;section=backups" onsubmit="return window.confirm('<?= e(t('admin.backup_delete_confirm')) ?>');"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete_backup"><input type="hidden" name="backup_id" value="<?= $backupId ?>"><button class="btn btn-ghost small btn-danger-ghost" type="submit"><?= e(t('common.delete')) ?></button></form>
                                </div>
                            </details>
                        </div>
                    </article>
                <?php endforeach; ?>
                <div class="admin-backups-empty" data-backup-empty <?= $backupRows === [] ? '' : 'hidden' ?>><span aria-hidden="true"><?= activity_icon_svg('shield') ?></span><strong><?= e($backupRows === [] ? t('admin.backup_empty') : t('admin.backups_no_results')) ?></strong><?php if ($backupRows === []): ?><p><?= e(t('admin.backups_empty_hint')) ?></p><?php endif; ?></div>
            </div>
        </section>
    </div>

    <details class="admin-backups-disclosure admin-backups-maintenance">
        <summary><span aria-hidden="true"><?= activity_icon_svg('image') ?></span><span><strong><?= e(t('admin.backups_maintenance_title')) ?></strong><small><?= e(t('admin.backups_maintenance_hint')) ?></small></span><b aria-hidden="true">⌄</b></summary>
        <form method="post" action="/?page=admin&amp;section=backups" class="admin-backups-maintenance-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="regenerate_photo_thumbnails"><div><strong><?= e(t('admin.photo_thumbnails_title')) ?></strong><p><?= e(t('admin.photo_thumbnails_subtitle')) ?></p></div><button class="btn btn-ghost" type="submit"><?= e(t('admin.photo_thumbnails_regenerate')) ?></button></form>
    </details>
</article>
