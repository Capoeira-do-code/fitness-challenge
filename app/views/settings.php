<?php

declare(strict_types=1);

$layout = json_decode((string) ($currentUser['dashboard_layout_json'] ?? '[]'), true);
if (!is_array($layout)) {
    $layout = [];
}
$widgets = ['kpis', 'money', 'approvals', 'steps', 'weight', 'comparison', 'meals', 'ranking', 'weekly'];
$layoutOrder = array_flip($layout);
?>
<section class="screen stack-lg">
    <div class="hero-panel">
        <div>
            <p class="eyebrow"><?= e(t('nav.settings')) ?></p>
            <h1><?= e(t('settings.title')) ?></h1>
            <p class="muted"><?= e(t('settings.subtitle')) ?></p>
        </div>
        <a class="btn btn-ghost" href="/?page=logout"><?= e(t('nav.logout')) ?></a>
    </div>

    <div class="grid-two">
        <article class="panel">
            <h2><?= e(t('settings.avatar')) ?></h2>
            <form method="post" action="/?page=settings" enctype="multipart/form-data" class="stack">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="upload_avatar">
                <?php if (!empty($currentUser['avatar_path'])): ?>
                    <img class="settings-avatar-preview" src="<?= e((string) $currentUser['avatar_path']) ?>" alt="<?= e((string) $currentUser['display_name']) ?>">
                <?php endif; ?>
                <label><?= e(t('settings.avatar_file')) ?><input type="file" name="avatar" accept="image/*" required></label>
                <button class="btn btn-primary" type="submit"><?= e(t('common.save')) ?></button>
            </form>
        </article>

        <article class="panel">
            <h2><?= e(t('profile.security')) ?></h2>
            <form method="post" action="/?page=settings" class="stack">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="change_password">
                <label><?= e(t('common.current_password')) ?><input type="password" name="current_password" required></label>
                <label><?= e(t('common.new_password')) ?><input type="password" name="new_password" minlength="8" required></label>
                <label><?= e(t('common.repeat_password')) ?><input type="password" name="new_password_confirm" minlength="8" required></label>
                <button class="btn btn-secondary" type="submit"><?= e(t('profile.update_password')) ?></button>
            </form>
        </article>
    </div>

    <article class="panel">
        <h2><?= e(t('settings.preferences')) ?></h2>
        <form method="post" action="/?page=set_locale" class="stack compact-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="redirect_to" value="/?page=settings">
            <label><?= e(t('common.language')) ?><select name="locale"><?php foreach (locale_options() as $locale => $label): ?><option value="<?= e($locale) ?>" <?= $locale === current_locale() ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
            <button class="btn btn-primary" type="submit"><?= e(t('common.save')) ?></button>
        </form>

        <form method="post" action="/?page=settings" class="stack">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="update_preferences">
            <div class="grid-inline">
                <label><?= e(t('settings.primary_goal')) ?><select name="primary_goal_type"><option value="steps" <?= ($currentUser['primary_goal_type'] ?? 'steps') === 'steps' ? 'selected' : '' ?>><?= e(t('metric.steps')) ?></option><option value="km" <?= ($currentUser['primary_goal_type'] ?? 'steps') === 'km' ? 'selected' : '' ?>><?= e(t('metric.distance_km')) ?></option></select></label>
                <label><?= e(t('settings.primary_goal_value')) ?><input type="number" step="0.01" name="primary_goal_value" value="<?= e((string) ($currentUser['primary_goal_value'] ?? '')) ?>"></label>
                <label><?= e(t('dashboard.viewing')) ?><select name="dashboard_view"><option value="current_week" <?= ($currentUser['dashboard_view'] ?? '') === 'current_week' ? 'selected' : '' ?>><?= e(t('dashboard.current_week')) ?></option><option value="total" <?= ($currentUser['dashboard_view'] ?? '') === 'total' ? 'selected' : '' ?>><?= e(t('metric.total')) ?></option></select></label>
            </div>
            <div class="chip-group">
                <span><?= e(t('settings.dashboard_widgets')) ?>:</span>
                <?php foreach ($widgets as $widget): ?>
                    <label class="chip">
                        <input type="checkbox" name="dashboard_widgets[]" value="<?= e($widget) ?>" <?= $layout === [] || in_array($widget, $layout, true) ? 'checked' : '' ?>>
                        <?= e(t('dashboard.widget_' . $widget)) ?>
                        <input type="number" name="dashboard_order[<?= e($widget) ?>]" value="<?= e((string) (($layoutOrder[$widget] ?? array_search($widget, $widgets, true)) + 1)) ?>" min="1" max="<?= count($widgets) ?>" aria-label="<?= e(t('settings.dashboard_widgets')) ?>">
                    </label>
                <?php endforeach; ?>
            </div>
            <button class="btn btn-primary" type="submit"><?= e(t('common.save')) ?></button>
        </form>
    </article>
</section>
