<?php

declare(strict_types=1);

$achievementFormItem = is_array($achievementFormItem ?? null) ? (array) $achievementFormItem : [];
$achievementIsNew = !empty($achievementIsNew);
$achievementFormId = (int) ($achievementFormItem['id'] ?? 0);
$achievementTrigger = trim((string) ($achievementFormItem['trigger_key'] ?? ''));
$achievementConditional = !$achievementIsNew && $achievementTrigger !== '' && !empty($achievementFormItem['rule_id']);
$achievementMetric = str_starts_with($achievementTrigger, 'habit:') ? 'habit_completion' : $achievementTrigger;
if ($achievementMetric === 'km') {
    $achievementMetric = 'distance_km';
}
if (!in_array($achievementMetric, ['steps', 'distance_km', 'workouts', 'score', 'strikes', 'penalties', 'weight', 'strength_rank', 'habit_completion'], true)) {
    $achievementMetric = 'steps';
}
$achievementHabitCode = str_starts_with($achievementTrigger, 'habit:') ? substr($achievementTrigger, 6) : '';
$achievementOperator = (string) ($achievementFormItem['trigger_operator'] ?? '>=');
$achievementTarget = (string) ($achievementFormItem['trigger_target'] ?? '1');
$achievementWindow = (string) ($achievementFormItem['trigger_window'] ?? 'total');
if ($achievementWindow === 'week') {
    $achievementWindow = 'current_week';
}
$achievementIconKey = normalize_achievement_icon_key((string) ($achievementFormItem['icon_key'] ?? 'trophy'));
$achievementTranslations = is_array($achievementFormItem['translations_by_locale'] ?? null) ? (array) $achievementFormItem['translations_by_locale'] : [];
$achievementImageUrl = media_url((string) ($achievementFormItem['image_path'] ?? ''));
?>
<div class="admin-achievement-editor-head">
    <div>
        <p class="eyebrow"><?= e(t($achievementIsNew ? 'admin.achievements_new_eyebrow' : 'admin.achievements_edit_eyebrow')) ?></p>
        <h2><?= e($achievementIsNew ? t('achievements.create') : (string) ($achievementFormItem['name'] ?? '')) ?></h2>
        <p><?= e(t('admin.achievements_editor_hint')) ?></p>
    </div>
    <?php $renderAdminBack('/?page=admin&section=achievements', t('admin.section_achievements')); ?>
</div>

<form method="post" action="/?page=admin" enctype="multipart/form-data" class="admin-achievement-form" data-achievement-form>
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="<?= $achievementIsNew ? 'create_achievement' : 'update_achievement' ?>">
    <?php if (!$achievementIsNew): ?><input type="hidden" name="achievement_id" value="<?= $achievementFormId ?>"><?php endif; ?>

    <section class="admin-achievement-form-card admin-achievement-identity-card">
        <div class="admin-achievement-form-card-head">
            <span aria-hidden="true"><?= activity_icon_svg('target') ?></span>
            <div><strong><?= e(t('admin.achievements_identity_title')) ?></strong><small><?= e(t('admin.achievements_identity_hint')) ?></small></div>
        </div>
        <div class="admin-achievement-core-fields">
            <label><span><?= e(t('admin.achievements_code')) ?></span><input type="text" name="code" value="<?= e((string) ($achievementFormItem['code'] ?? '')) ?>" placeholder="million_steps" pattern="[a-z0-9_]+" autocomplete="off"></label>
            <label><span><?= e(t('achievements.scope')) ?></span><select name="scope"><option value="user" <?= (string) ($achievementFormItem['scope'] ?? 'user') === 'user' ? 'selected' : '' ?>><?= e(t('common.user')) ?></option><option value="team" <?= (string) ($achievementFormItem['scope'] ?? '') === 'team' ? 'selected' : '' ?>><?= e(t('nav.team')) ?></option></select></label>
            <label class="admin-achievement-publish"><span><strong><?= e(t('admin.achievements_publish_label')) ?></strong><small><?= e(t('admin.achievements_publish_hint')) ?></small></span><input type="checkbox" name="active" value="1" <?= $achievementIsNew || (int) ($achievementFormItem['active'] ?? 1) === 1 ? 'checked' : '' ?>></label>
        </div>
    </section>

    <section class="admin-achievement-form-card admin-achievement-visual-card">
        <div class="admin-achievement-form-card-head">
            <span aria-hidden="true"><?= activity_icon_svg('image') ?></span>
            <div><strong><?= e(t('admin.achievements_visual_title')) ?></strong><small><?= e(t('admin.achievements_visual_hint')) ?></small></div>
        </div>
        <div class="admin-achievement-visual-builder">
            <div class="admin-achievement-live-preview" data-achievement-image-preview-wrap>
                <span data-achievement-icon-preview <?= $achievementImageUrl !== '' ? 'hidden' : '' ?>><?= achievement_icon_svg($achievementIconKey) ?></span>
                <img src="<?= e($achievementImageUrl) ?>" alt="" data-achievement-image-preview <?= $achievementImageUrl === '' ? 'hidden' : '' ?>>
            </div>
            <div class="admin-achievement-upload-copy">
                <strong><?= e(t('achievements.custom_image')) ?></strong>
                <small><?= e(t('admin.achievements_image_hint')) ?></small>
                <label class="btn btn-secondary small admin-achievement-file-button">
                    <span><?= e(t('admin.achievements_choose_image')) ?></span>
                    <input type="file" name="image" accept="image/jpeg,image/png,image/webp" data-achievement-image-input>
                </label>
                <?php if ($achievementImageUrl !== ''): ?><label class="check"><input type="checkbox" name="remove_image" value="1" data-achievement-remove-image><?= e(t('achievements.remove_image')) ?></label><?php endif; ?>
            </div>
        </div>
        <details class="admin-achievement-icon-disclosure">
            <summary><span><?= e(t('admin.achievements_icon_fallback')) ?></span><small><?= e(t('admin.achievements_icon_hint')) ?></small><b aria-hidden="true">⌄</b></summary>
            <div class="achievement-icon-picker" role="radiogroup" aria-label="<?= e(t('achievements.icon')) ?>">
                <?php foreach ($achievementIconOptions as $iconKey => $iconLabel): ?>
                    <label class="achievement-icon-option" title="<?= e((string) $iconLabel) ?>">
                        <input type="radio" name="icon_key" value="<?= e((string) $iconKey) ?>" <?= $achievementIconKey === (string) $iconKey ? 'checked' : '' ?>>
                        <span class="achievement-icon-option-media"><?= achievement_icon_svg((string) $iconKey) ?></span>
                        <span><?= e((string) $iconLabel) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
        </details>
    </section>

    <section class="admin-achievement-form-card">
        <div class="admin-achievement-form-card-head">
            <span aria-hidden="true"><?= activity_icon_svg('list') ?></span>
            <div><strong><?= e(t('admin.achievements_languages_title')) ?></strong><small><?= e(t('admin.achievements_languages_hint')) ?></small></div>
        </div>
        <div class="achievement-translation-fields">
            <?php foreach ($achievementLocales as $localeCode => $localeLabel): ?>
                <?php
                $translation = is_array($achievementTranslations[$localeCode] ?? null) ? (array) $achievementTranslations[$localeCode] : [];
                if ($localeCode === 'en' && !$achievementIsNew) {
                    $translation = [
                        'name' => (string) ($translation['name'] ?? $achievementFormItem['name'] ?? ''),
                        'description' => (string) ($translation['description'] ?? $achievementFormItem['description'] ?? ''),
                        'reward_text' => (string) ($translation['reward_text'] ?? $achievementFormItem['reward_text'] ?? ''),
                    ];
                }
                ?>
                <details class="achievement-translation-card" <?= $localeCode === 'en' ? 'open' : '' ?>>
                    <summary><strong><?= e((string) $localeLabel) ?></strong><span><?= $localeCode === 'en' ? e(t('admin.achievements_fallback_badge')) : strtoupper(e((string) $localeCode)) ?></span><b aria-hidden="true">⌄</b></summary>
                    <div>
                        <label><span><?= e(t('achievements.name')) ?></span><input type="text" name="translations[<?= e((string) $localeCode) ?>][name]" value="<?= e((string) ($translation['name'] ?? '')) ?>" maxlength="80" <?= $localeCode === 'en' ? 'required' : '' ?>></label>
                        <label><span><?= e(t('achievements.description')) ?></span><textarea name="translations[<?= e((string) $localeCode) ?>][description]" rows="2" maxlength="220"><?= e((string) ($translation['description'] ?? '')) ?></textarea></label>
                        <label><span><?= e(t('achievements.reward')) ?></span><input type="text" name="translations[<?= e((string) $localeCode) ?>][reward_text]" value="<?= e((string) ($translation['reward_text'] ?? '')) ?>" maxlength="100"></label>
                    </div>
                </details>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="admin-achievement-form-card">
        <div class="admin-achievement-rule-toggle">
            <span aria-hidden="true"><?= activity_icon_svg('bolt') ?></span>
            <span><strong><?= e(t('achievements.conditional')) ?></strong><small><?= e(t('admin.achievements_rule_hint')) ?></small></span>
            <input type="checkbox" name="conditional_enabled" value="1" data-achievement-conditional-toggle <?= $achievementConditional ? 'checked' : '' ?>>
        </div>
        <div class="admin-achievement-rule-grid" data-achievement-conditional-fields <?= $achievementConditional ? '' : 'hidden' ?>>
            <label><span><?= e(t('achievements.metric')) ?></span><select name="metric" data-achievement-metric>
                <?php foreach (['steps' => 'Steps', 'distance_km' => 'Distance km', 'workouts' => 'Workouts', 'score' => 'Score', 'strikes' => 'Strikes', 'weight' => 'Weight', 'strength_rank' => 'Strength rank', 'habit_completion' => 'Habit'] as $metricValue => $metricLabel): ?><option value="<?= e($metricValue) ?>" <?= $achievementMetric === $metricValue ? 'selected' : '' ?>><?= e($metricLabel) ?></option><?php endforeach; ?>
                <?php if ($penaltiesEnabled || $achievementMetric === 'penalties'): ?><option value="penalties" <?= $achievementMetric === 'penalties' ? 'selected' : '' ?>>Penalties</option><?php endif; ?>
            </select></label>
            <label data-achievement-habit-wrap <?= $achievementMetric === 'habit_completion' ? '' : 'hidden' ?>><span>Habit</span><select name="habit_code"><?php foreach (($habits ?? []) as $habit): ?><option value="<?= e((string) $habit['code']) ?>" <?= (string) $habit['code'] === $achievementHabitCode ? 'selected' : '' ?>><?= e((string) $habit['label']) ?></option><?php endforeach; ?></select></label>
            <label><span><?= e(t('achievements.operator')) ?></span><select name="operator"><?php foreach (['>=', '<=', '=', '>', '<'] as $operator): ?><option value="<?= e($operator) ?>" <?= $achievementOperator === $operator ? 'selected' : '' ?>><?= e($operator) ?></option><?php endforeach; ?></select></label>
            <label><span><?= e(t('achievements.target')) ?></span><input type="number" step="0.1" name="target_amount" value="<?= e($achievementTarget) ?>"></label>
            <label><span><?= e(t('achievements.window')) ?></span><select name="window"><?php foreach (['total', 'current_week', 'current_month', 'current_challenge'] as $window): ?><option value="<?= e($window) ?>" <?= $achievementWindow === $window ? 'selected' : '' ?>><?= e(t('admin.achievements_window_' . $window)) ?></option><?php endforeach; ?></select></label>
        </div>
    </section>

    <div class="admin-achievement-form-actions">
        <a class="btn btn-ghost" href="/?page=admin&section=achievements"><?= e(t('common.cancel')) ?></a>
        <button class="btn btn-primary" type="submit"><?= e($achievementIsNew ? t('achievements.create') : t('common.save')) ?></button>
    </div>
</form>

<?php if (!$achievementIsNew && (int) ($achievementFormItem['active'] ?? 1) === 1): ?>
<form method="post" action="/?page=admin" class="admin-achievement-pause-form" onsubmit="return confirm('<?= e(t('admin.deactivate_achievement_confirm')) ?>');">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="deactivate_achievement">
    <input type="hidden" name="achievement_id" value="<?= $achievementFormId ?>">
    <span><strong><?= e(t('admin.achievements_pause_title')) ?></strong><small><?= e(t('admin.achievements_pause_hint')) ?></small></span>
    <button class="btn btn-ghost small" type="submit"><?= e(t('admin.achievements_pause_action')) ?></button>
</form>
<?php endif; ?>
