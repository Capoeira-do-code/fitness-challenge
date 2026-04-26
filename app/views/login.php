<?php

declare(strict_types=1);
?>
<section class="auth-wrap">
    <div class="auth-shell">
        <div class="auth-copy">
            <span class="brand-mark large">FC</span>
            <p class="eyebrow"><?= e(t('app.short_name')) ?></p>
            <h1 data-i18n="login.title"><?= e(t('login.title')) ?></h1>
            <p data-i18n="login.subtitle"><?= e(t('login.subtitle')) ?></p>
        </div>

        <div class="panel auth-card">
            <form method="post" action="/?page=login" class="stack">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

                <label>
                    <span data-i18n="common.username"><?= e(t('common.username')) ?></span>
                    <input type="text" name="username" autocomplete="username" required>
                </label>

                <label>
                    <span data-i18n="common.password"><?= e(t('common.password')) ?></span>
                    <input type="password" name="password" autocomplete="current-password" required>
                </label>

                <button class="btn btn-primary btn-block" type="submit" data-i18n="login.submit"><?= e(t('login.submit')) ?></button>
            </form>
        </div>
    </div>
</section>
<?php
$originalLocale = current_locale();
$loginLocaleDictionary = [];
foreach (array_keys(locale_options()) as $locale) {
    set_current_locale($locale);
    $loginLocaleDictionary[$locale] = [
        'login.title' => t('login.title'),
        'login.subtitle' => t('login.subtitle'),
        'common.username' => t('common.username'),
        'common.password' => t('common.password'),
        'login.submit' => t('login.submit'),
    ];
}
set_current_locale($originalLocale);
?>
<script type="application/json" id="login-i18n-dictionary"><?= json_encode($loginLocaleDictionary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
