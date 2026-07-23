<?php

declare(strict_types=1);

$achievementRows = is_array($adminAchievements ?? null) ? (array) $adminAchievements : [];
$achievementAwardsRows = is_array($achievementAwards ?? null) ? (array) $achievementAwards : [];
$achievementActiveCount = 0;
$achievementPausedCount = 0;
$achievementAutomaticCount = 0;
$achievementCustomVisualCount = 0;
foreach ($achievementRows as $achievementRow) {
    (int) ($achievementRow['active'] ?? 1) === 1 ? $achievementActiveCount++ : $achievementPausedCount++;
    !empty($achievementRow['rule_id']) ? $achievementAutomaticCount++ : null;
    trim((string) ($achievementRow['image_path'] ?? '')) !== '' ? $achievementCustomVisualCount++ : null;
}
?>
<article class="panel settings-panel active admin-achievements-page" data-spa-section="achievements">
    <?php if ($selectedAchievementId === ''): ?>
        <section class="admin-achievements-overview">
            <div class="admin-achievements-overview-head">
                <span aria-hidden="true"><?= achievement_icon_svg('trophy') ?></span>
                <div><p class="eyebrow"><?= e(t('admin.section_achievements')) ?></p><h2><?= e(t('admin.achievements_library_title')) ?></h2><p><?= e(t('admin.achievements_help')) ?></p></div>
                <div class="admin-achievements-overview-actions">
                    <a class="btn btn-primary small" href="/?page=admin&section=achievements&achievement_id=new"><?= e(t('achievements.create')) ?></a>
                    <?php $renderAdminBack('/?page=admin&group=training', t('admin.group_training')); ?>
                </div>
            </div>
            <div class="admin-achievements-kpis">
                <span><strong><?= count($achievementRows) ?></strong><small><?= e(t('admin.achievements_total')) ?></small></span>
                <span><strong><?= $achievementActiveCount ?></strong><small><?= e(t('common.active')) ?></small></span>
                <span><strong><?= $achievementAutomaticCount ?></strong><small><?= e(t('admin.achievements_automatic')) ?></small></span>
                <span><strong><?= $achievementCustomVisualCount ?></strong><small><?= e(t('admin.achievements_custom_visuals')) ?></small></span>
            </div>
        </section>

        <section class="admin-achievements-catalog">
            <div class="admin-achievements-section-head">
                <div><h3><?= e(t('admin.achievements_catalog_title')) ?></h3><p><?= e(t('admin.achievements_catalog_hint')) ?></p></div>
                <label class="admin-achievements-search"><span aria-hidden="true"><?= activity_icon_svg('search') ?></span><input type="search" placeholder="<?= e(t('admin.achievements_search_placeholder')) ?>" autocomplete="off" data-achievement-search></label>
            </div>
            <div class="admin-achievements-list" data-achievement-list>
                <?php foreach ($achievementRows as $achievement): ?>
                    <?php
                    $triggerKey = trim((string) ($achievement['trigger_key'] ?? ''));
                    $isAutomatic = !empty($achievement['rule_id']);
                    $conditionSummary = t('admin.achievements_manual');
                    if ($isAutomatic) {
                        $conditionSummary = $triggerKey . ' ' . (string) ($achievement['trigger_operator'] ?? '>=') . ' ' . format_achievement_progress_number((float) ($achievement['trigger_target'] ?? 1), achievement_metric_unit((string) ($achievement['metric_key'] ?? $triggerKey))) . ' · ' . t('admin.achievements_window_' . (string) ($achievement['trigger_window'] ?? 'total'));
                    } elseif ($triggerKey !== '') {
                        $conditionSummary = t('admin.achievements_system_rule');
                    }
                    $achievementSearchText = trim((string) ($achievement['name'] ?? '') . ' ' . (string) ($achievement['description'] ?? '') . ' ' . (string) ($achievement['code'] ?? '') . ' ' . (string) ($achievement['scope'] ?? ''));
                    $achievementSearch = function_exists('mb_strtolower') ? mb_strtolower($achievementSearchText) : strtolower($achievementSearchText);
                    ?>
                    <a class="admin-achievement-list-row<?= (int) ($achievement['active'] ?? 1) === 1 ? '' : ' is-paused' ?>" href="/?page=admin&section=achievements&achievement_id=<?= (int) $achievement['id'] ?>" data-achievement-row data-achievement-search-value="<?= e($achievementSearch) ?>">
                        <?= achievement_visual_html($achievement, 'achievement-visual admin-achievement-list-visual') ?>
                        <span class="admin-achievement-list-copy"><strong><?= e((string) $achievement['name']) ?></strong><small><?= e((string) ($achievement['description'] ?? '')) ?></small><em><?= e($conditionSummary) ?></em></span>
                        <span class="admin-achievement-list-meta"><b><?= e((string) ($achievement['scope'] ?? 'user') === 'team' ? t('nav.team') : t('common.user')) ?></b><i class="<?= (int) ($achievement['active'] ?? 1) === 1 ? 'is-active' : '' ?>"><?= e((int) ($achievement['active'] ?? 1) === 1 ? t('common.active') : t('common.inactive')) ?></i></span>
                        <span class="settings-chevron" aria-hidden="true">›</span>
                    </a>
                <?php endforeach; ?>
                <p class="admin-achievements-no-results" data-achievement-no-results hidden><?= e(t('admin.achievements_no_results')) ?></p>
            </div>
        </section>

        <div class="admin-achievements-secondary-grid">
            <details class="admin-achievements-secondary-card">
                <summary><span aria-hidden="true"><?= activity_icon_svg('plus') ?></span><span><strong><?= e(t('achievements.grant')) ?></strong><small><?= e(t('admin.achievements_grant_hint')) ?></small></span><b aria-hidden="true">⌄</b></summary>
                <form method="post" action="/?page=admin" class="admin-achievement-grant-form">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="grant_achievement">
                    <label><span><?= e(t('achievements.title')) ?></span><select name="achievement_id"><?php foreach (($achievements ?? []) as $achievement): ?><option value="<?= (int) $achievement['id'] ?>"><?= e((string) $achievement['name']) ?></option><?php endforeach; ?></select></label>
                    <label><span><?= e(t('achievements.scope')) ?></span><select name="scope"><option value="user"><?= e(t('common.user')) ?></option><option value="team"><?= e(t('nav.team')) ?></option></select></label>
                    <label><span><?= e(t('common.user')) ?></span><select name="user_id"><?php foreach ($users as $user): ?><option value="<?= (int) $user['id'] ?>"><?= e((string) $user['display_name']) ?></option><?php endforeach; ?></select></label>
                    <input type="hidden" name="team_id" value="<?= (int) $team['id'] ?>">
                    <label class="admin-achievement-grant-note"><span><?= e(t('common.notes')) ?></span><input type="text" name="note" maxlength="160"></label>
                    <button class="btn btn-secondary" type="submit"><?= e(t('achievements.grant')) ?></button>
                </form>
            </details>
            <details class="admin-achievements-secondary-card">
                <summary><span aria-hidden="true"><?= activity_icon_svg('medal') ?></span><span><strong><?= e(t('admin.achievement_awards')) ?></strong><small><?= e(t('admin.achievements_awards_hint', ['count' => count($achievementAwardsRows)])) ?></small></span><b aria-hidden="true">⌄</b></summary>
                <div class="admin-achievement-awards-list">
                    <?php foreach (array_slice($achievementAwardsRows, 0, 50) as $award): ?>
                        <form method="post" action="/?page=admin" class="admin-achievement-award-row"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete_achievement_award"><input type="hidden" name="award_id" value="<?= (int) $award['id'] ?>"><span><strong><?= e((string) $award['name']) ?></strong><small><?= e((string) ($award['owner_name'] ?? $award['team_name'] ?? '')) ?> · <?= e(format_date_eu((string) $award['awarded_at'])) ?></small></span><button class="btn small btn-ghost" type="submit"><?= e(t('common.delete')) ?></button></form>
                    <?php endforeach; ?>
                    <?php if ($achievementAwardsRows === []): ?><p class="muted panel-inline-empty"><?= e(t('achievements.empty')) ?></p><?php endif; ?>
                </div>
            </details>
        </div>
    <?php elseif ($selectedAchievementId === 'new'): ?>
        <?php $achievementFormItem = []; $achievementIsNew = true; require __DIR__ . '/admin_achievement_form.php'; ?>
    <?php elseif (is_array($selectedAdminAchievement)): ?>
        <?php $achievementFormItem = $selectedAdminAchievement; $achievementIsNew = false; require __DIR__ . '/admin_achievement_form.php'; ?>
    <?php else: ?>
        <div class="admin-achievement-not-found"><p><?= e(t('flash.not_found')) ?></p><?php $renderAdminBack('/?page=admin&section=achievements', t('admin.section_achievements')); ?></div>
    <?php endif; ?>
</article>
