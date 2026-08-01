<?php

declare(strict_types=1);

$loginIconUrl = trim((string) ($loginAppIconUrl ?? ''));
$appName = (string) (app_setting($GLOBALS['pdo'], 'app_name', (string) ($config['app_name'] ?? 'Fitness Challenge Tracker')) ?? 'Fitness Challenge Tracker');
$loginRememberDefault = !empty($loginRememberDefault);
$publicRegistrationEnabled = !empty($publicRegistrationEnabled);
$loginRetryUsername = trim((string) ($_GET['username'] ?? ''));
// The visual variant is retained for compatibility; this screen ships one bold look.
$loginStyle = login_style_normalize($loginStyle ?? 'split');
?>
<section class="loginx" data-login-scene>
    <div class="loginx-aurora" aria-hidden="true">
        <span class="loginx-orb loginx-orb-a"></span>
        <span class="loginx-orb loginx-orb-b"></span>
        <span class="loginx-orb loginx-orb-c"></span>
        <span class="loginx-mesh"></span>
        <span class="loginx-noise"></span>
    </div>

    <div class="loginx-shell">
        <aside class="loginx-hero">
            <div class="loginx-brand">
                <span class="loginx-brand-mark" aria-hidden="true">
                    <?php if ($loginIconUrl !== ''): ?>
                        <img src="<?= e($loginIconUrl) ?>" alt="">
                    <?php else: ?>
                        <?= activity_icon_svg('dumbbell') ?>
                    <?php endif; ?>
                </span>
                <span class="loginx-brand-name"><?= e($appName) ?></span>
            </div>

            <div class="loginx-hero-copy">
                <p class="loginx-eyebrow"><span class="loginx-live-dot" aria-hidden="true"></span><?= e(t('login.card_subtitle')) ?></p>
                <h1 class="loginx-headline"><?= e(t('login.title')) ?></h1>
                <p class="loginx-tagline"><?= e(t('login.subtitle')) ?></p>
            </div>

            <div class="loginx-equalizer" aria-hidden="true">
                <?php for ($i = 0; $i < 9; $i++): ?><i></i><?php endfor; ?>
            </div>
        </aside>

        <div class="loginx-panel">
            <div class="loginx-card">
                <div class="loginx-card-head">
                    <span class="loginx-card-logo" aria-hidden="true">
                        <?php if ($loginIconUrl !== ''): ?>
                            <img src="<?= e($loginIconUrl) ?>" alt="">
                        <?php else: ?>
                            <?= activity_icon_svg('dumbbell') ?>
                        <?php endif; ?>
                    </span>
                    <h2 class="loginx-card-title"><?= e(t('login.card_title')) ?></h2>
                    <p class="loginx-card-subtitle"><?= e($appName) ?></p>
                </div>

                <form method="post" action="/?page=login" class="loginx-form">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

                    <label class="loginx-field">
                        <span class="loginx-field-label"><?= e(t('common.username')) ?></span>
                        <span class="loginx-field-control">
                            <span class="loginx-field-icon" aria-hidden="true"><?= activity_icon_svg('user') ?></span>
                            <input type="text" name="username" autocomplete="username" required<?= $loginRetryUsername === '' ? ' autofocus' : '' ?> placeholder="<?= e(t('common.username')) ?>" value="<?= e($loginRetryUsername) ?>">
                        </span>
                    </label>

                    <label class="loginx-field">
                        <span class="loginx-field-label"><?= e(t('common.password')) ?></span>
                        <span class="loginx-field-control">
                            <span class="loginx-field-icon" aria-hidden="true"><?= activity_icon_svg('shield') ?></span>
                            <input type="password" name="password" autocomplete="current-password" required<?= $loginRetryUsername !== '' ? ' autofocus' : '' ?> placeholder="••••••••">
                        </span>
                    </label>

                    <label class="loginx-remember">
                        <input type="checkbox" name="remember_me" value="1" <?= $loginRememberDefault ? 'checked' : '' ?>>
                        <span class="loginx-remember-box" aria-hidden="true"></span>
                        <span class="loginx-remember-text"><?= e(t('login.remember_me')) ?></span>
                    </label>

                    <button class="loginx-submit" type="submit">
                        <span><?= e(t('login.submit')) ?></span>
                        <span class="loginx-submit-arrow" aria-hidden="true">&rarr;</span>
                    </button>
                </form>
                <?php if ($publicRegistrationEnabled): ?>
                    <p class="loginx-register">
                        <span><?= e(t('login.new_account_hint')) ?></span>
                        <a href="/?page=register"><?= e(t('login.create_account')) ?><span aria-hidden="true">&rarr;</span></a>
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>
