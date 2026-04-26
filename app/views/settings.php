<?php

declare(strict_types=1);

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
            <h2 id="avatar"><?= e(t('settings.avatar')) ?></h2>
            <form method="post" action="/?page=settings" enctype="multipart/form-data" class="stack" data-image-cropper-form>
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="upload_avatar">
                <input type="hidden" name="avatar_cropped" value="" data-image-crop-output>
                <?php $settingsAvatarUrl = avatar_url($currentUser); ?>
                <?php if ($settingsAvatarUrl !== ''): ?>
                    <img class="settings-avatar-preview settings-avatar-preview-round" src="<?= e($settingsAvatarUrl) ?>" alt="<?= e((string) $currentUser['display_name']) ?>">
                <?php endif; ?>
                <div class="image-cropper" data-image-cropper>
                    <canvas width="320" height="320" data-image-crop-canvas></canvas>
                    <p class="muted small" data-image-crop-empty>Selecciona una imagen para recortarla en formato 1:1.</p>
                    <label>
                        Zoom
                        <input type="range" min="1" max="3" step="0.01" value="1" data-image-crop-zoom>
                    </label>
                </div>
                <label><?= e(t('settings.avatar_file')) ?><input type="file" name="avatar" accept="image/*" required data-image-crop-input></label>
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
        <p class="eyebrow"><?= e(t('common.language')) ?></p>
        <?php
        $localeScope = 'settings';
        $localeFormClass = 'stack compact-form';
        $localeSelectId = 'locale-select-settings';
        $localeRedirectTo = '/?page=settings';
        $localeShowSaveButton = true;
        $localeAsync = false;
        require __DIR__ . '/components/locale_selector.php';
        ?>

        <form method="post" action="/?page=settings" class="stack">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="update_preferences">
            <div class="grid-inline">
                <label><?= e(t('settings.primary_goal')) ?><select name="primary_goal_type"><option value="steps" <?= ($currentUser['primary_goal_type'] ?? 'steps') === 'steps' ? 'selected' : '' ?>><?= e(t('metric.steps')) ?></option><option value="km" <?= ($currentUser['primary_goal_type'] ?? 'steps') === 'km' ? 'selected' : '' ?>><?= e(t('metric.distance_km')) ?></option></select></label>
                <label><?= e(t('settings.primary_goal_value')) ?><input type="number" step="0.01" name="primary_goal_value" value="<?= e((string) ($currentUser['primary_goal_value'] ?? '')) ?>"></label>
                <label><?= e(t('dashboard.viewing')) ?><select name="dashboard_view"><option value="current_week" <?= ($currentUser['dashboard_view'] ?? '') === 'current_week' ? 'selected' : '' ?>><?= e(t('dashboard.current_week')) ?></option><option value="total" <?= ($currentUser['dashboard_view'] ?? '') === 'total' ? 'selected' : '' ?>><?= e(t('metric.total')) ?></option></select></label>
            </div>
            <button class="btn btn-primary" type="submit"><?= e(t('common.save')) ?></button>
        </form>
    </article>
</section>
