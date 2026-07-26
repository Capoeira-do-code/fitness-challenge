<?php

declare(strict_types=1);

$values = is_array($setupValues ?? null) ? $setupValues : [];
?>
<section class="auth-wrap setup-wrap">
    <div class="panel auth-card setup-card">
        <div class="auth-card-header setup-card-header">
            <span class="brand-mark login-card-logo-mark"><?= e(initials_for((string) ($values['app_name'] ?? 'FC'))) ?></span>
            <div class="setup-card-heading">
                <p class="eyebrow"><?= e(t('setup.eyebrow')) ?></p>
                <h1 class="auth-card-title"><?= e(t('setup.title')) ?></h1>
                <p class="auth-card-subtitle"><?= e(t('setup.subtitle')) ?></p>
            </div>
        </div>

        <?php if (($setupError ?? '') !== ''): ?>
            <div class="alert error" role="alert"><?= e((string) $setupError) ?></div>
        <?php endif; ?>

        <form method="post" action="/?page=setup" class="stack setup-form" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

            <fieldset class="setup-section">
                <legend><span class="setup-section-number">1</span><?= e(t('setup.site_settings_legend')) ?></legend>
                <div class="setup-grid">
                    <label class="setup-field"><span><?= e(t('setup.app_name_label')) ?></span><input type="text" name="app_name" maxlength="80" value="<?= e((string) ($values['app_name'] ?? '')) ?>" required></label>
                    <label><span><?= e(t('setup.locale_label')) ?></span>
                        <select name="locale">
                            <?php foreach (locale_options() as $code => $label): ?>
                                <option value="<?= e($code) ?>" <?= ($values['locale'] ?? '') === $code ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label><span><?= e(t('setup.challenge_name_label')) ?></span><input type="text" name="challenge_name" maxlength="100" value="<?= e((string) ($values['challenge_name'] ?? '')) ?>" required></label>
                    <label><span><?= e(t('setup.team_name_label')) ?></span><input type="text" name="team_name" maxlength="100" value="<?= e((string) ($values['team_name'] ?? '')) ?>" required></label>
                    <div class="setup-date-grid">
                        <label><span><?= e(t('setup.challenge_start_label')) ?></span><input type="date" name="challenge_start" value="<?= e((string) ($values['challenge_start'] ?? '')) ?>" required></label>
                        <label><span><?= e(t('setup.challenge_end_label')) ?></span><input type="date" name="challenge_end" value="<?= e((string) ($values['challenge_end'] ?? '')) ?>" required></label>
                    </div>
                </div>
            </fieldset>

            <fieldset class="setup-section">
                <legend><span class="setup-section-number">2</span><?= e(t('setup.admin_account_legend')) ?></legend>
                <div class="setup-grid">
                    <label><span><?= e(t('setup.display_name_label')) ?></span><input type="text" name="display_name" maxlength="80" value="<?= e((string) ($values['display_name'] ?? '')) ?>" autocomplete="name" required></label>
                    <label><span><?= e(t('setup.username_label')) ?></span><input type="text" name="username" minlength="3" maxlength="40" pattern="[A-Za-z0-9_.\-]+" value="<?= e((string) ($values['username'] ?? 'admin')) ?>" autocomplete="username" required></label>
                    <label><span><?= e(t('setup.password_label')) ?></span><input type="password" name="password" minlength="10" autocomplete="new-password" required><small><?= e(t('setup.password_hint')) ?></small></label>
                    <label><span><?= e(t('setup.password_confirm_label')) ?></span><input type="password" name="password_confirm" minlength="10" autocomplete="new-password" required></label>
                </div>
            </fieldset>

            <div class="setup-submit">
                <button class="btn btn-primary btn-block" type="submit"><?= e(t('setup.submit')) ?></button>
            </div>
        </form>
    </div>
</section>
