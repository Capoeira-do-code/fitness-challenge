<?php

declare(strict_types=1);

$registrationMode = (string) ($registrationMode ?? '');
if (!in_array($registrationMode, ['invite', 'public', 'closed', 'invalid'], true)) {
    if (!empty($registrationIsPublic)) {
        $registrationMode = 'public';
    } elseif (!empty($registrationAllowed) || (string) ($registrationInviteStatus ?? 'invalid') === 'active') {
        $registrationMode = 'invite';
    } else {
        $registrationMode = trim((string) ($registrationToken ?? '')) === '' ? 'closed' : 'invalid';
    }
}
$registrationIsPublic = $registrationMode === 'public';
$registrationCanContinue = in_array($registrationMode, ['invite', 'public'], true);
$inviteLabel = trim((string) (($registrationInvite['label'] ?? '')));
?>
<section class="registration-shell" data-registration-page>
    <header class="registration-brand">
        <span class="registration-brand-mark" aria-hidden="true"><?= activity_icon_svg('users') ?></span>
        <div><p class="eyebrow"><?= e(t($registrationIsPublic ? 'register.public_eyebrow' : 'register.invited')) ?></p><h1><?= e(t('register.title')) ?></h1></div>
    </header>

    <article class="registration-card">
        <?php if (!$registrationCanContinue): ?>
            <div class="registration-invalid" role="alert">
                <span aria-hidden="true"><?= activity_icon_svg('shield') ?></span>
                <h2><?= e(t($registrationMode === 'closed' ? 'register.registration_closed_title' : 'register.invite_invalid_title')) ?></h2>
                <p><?= e(t($registrationMode === 'closed' ? 'register.registration_closed' : 'register.invite_invalid')) ?></p>
                <a class="btn btn-primary" href="/?page=login"><?= e(t('register.back_login')) ?></a>
            </div>
        <?php else: ?>
            <div class="registration-copy">
                <h2><?= e(t('register.create_account')) ?></h2>
                <p><?= e($registrationIsPublic ? t('register.public_subtitle') : ($inviteLabel !== '' ? t('register.invite_for', ['label' => $inviteLabel]) : t('register.subtitle'))) ?></p>
            </div>
            <?php if ((string) ($registrationError ?? '') !== ''): ?>
                <div class="registration-error" role="alert"><?= e((string) $registrationError) ?></div>
            <?php endif; ?>
            <form method="post" action="/?page=register" class="registration-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="token" value="<?= e((string) ($registrationToken ?? '')) ?>">
                <div class="registration-grid">
                    <label><span><?= e(t('common.display_name')) ?></span><input type="text" name="display_name" maxlength="80" autocomplete="name" value="<?= e((string) ($_POST['display_name'] ?? '')) ?>" required autofocus></label>
                    <label><span><?= e(t('common.username')) ?></span><input type="text" name="username" minlength="3" maxlength="32" pattern="[A-Za-z0-9_.\-]+" autocomplete="username" autocapitalize="none" value="<?= e((string) ($_POST['username'] ?? '')) ?>" required><small><?= e(t('register.username_hint')) ?></small></label>
                    <label><span><?= e(t('common.password')) ?></span><input type="password" name="password" autocomplete="new-password" required data-caps-lock-field><small class="password-caps-warning" data-caps-lock-warning role="status" hidden><?= e(t('auth.caps_lock_on')) ?></small></label>
                    <label><span><?= e(t('register.password_confirm')) ?></span><input type="password" name="password_confirm" autocomplete="new-password" required data-caps-lock-field><small class="password-caps-warning" data-caps-lock-warning role="status" hidden><?= e(t('auth.caps_lock_on')) ?></small></label>
                    <label class="registration-locale"><span><?= e(t('common.language')) ?></span><select name="locale"><?php foreach (SUPPORTED_LOCALES as $locale): ?><option value="<?= e($locale) ?>" <?= normalize_locale((string) ($_POST['locale'] ?? current_locale()), 'en') === $locale ? 'selected' : '' ?>><?= e(t('locale.' . $locale)) ?></option><?php endforeach; ?></select></label>
                </div>
                <button class="btn btn-primary registration-submit" type="submit"><?= e(t('register.submit')) ?></button>
                <p class="registration-next-hint"><?= e(t('register.onboarding_hint')) ?></p>
            </form>
        <?php endif; ?>
    </article>
</section>
