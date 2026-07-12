<?php

declare(strict_types=1);

$loginIconUrl = trim((string) ($loginAppIconUrl ?? ''));
$appName = (string) (app_setting($GLOBALS['pdo'], 'app_name', (string) ($config['app_name'] ?? 'Fitness Challenge Tracker')) ?? 'Fitness Challenge Tracker');
$loginRememberDefault = !empty($loginRememberDefault);
$loginStyle = login_style_normalize($loginStyle ?? 'split');
?>
<section class="auth-wrap auth-wrap-login login-variant login-variant-<?= e($loginStyle) ?>" data-login-variant="<?= e($loginStyle) ?>">
    <div class="auth-shell auth-shell-login">
        <div class="auth-copy auth-copy-login">
            <div class="login-brand">
                <?php if ($loginIconUrl !== ''): ?>
                    <img class="login-brand-image" src="<?= e($loginIconUrl) ?>" alt="<?= e($appName) ?>">
                <?php else: ?>
                    <span class="brand-mark large login-brand-mark"><?= e(initials_for($appName)) ?></span>
                <?php endif; ?>
                <p class="eyebrow"><?= e($appName) ?></p>
            </div>
            <h1 class="login-headline"><?= e(t('login.title')) ?></h1>
            <p class="login-tagline"><?= e(t('login.subtitle')) ?></p>
        </div>

        <div class="panel auth-card auth-card-login">
            <div class="auth-card-header">
                <?php if ($loginIconUrl !== ''): ?>
                    <img class="login-card-logo" src="<?= e($loginIconUrl) ?>" alt="<?= e($appName) ?>">
                <?php else: ?>
                    <span class="brand-mark login-card-logo-mark"><?= e(initials_for($appName)) ?></span>
                <?php endif; ?>
                <h2 class="auth-card-title"><?= e(t('login.card_title')) ?></h2>
                <p class="auth-card-subtitle"><?= e(t('login.card_subtitle')) ?></p>
            </div>

            <form method="post" action="/?page=login" class="stack auth-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

                <label class="auth-field">
                    <span><?= e(t('common.username')) ?></span>
                    <input type="text" name="username" autocomplete="username" required autofocus>
                </label>

                <label class="auth-field">
                    <span><?= e(t('common.password')) ?></span>
                    <input type="password" name="password" autocomplete="current-password" required>
                </label>

                <label class="check remember-me-check">
                    <input type="checkbox" name="remember_me" value="1" <?= $loginRememberDefault ? 'checked' : '' ?>>
                    <span><?= e(t('login.remember_me')) ?></span>
                </label>

                <button class="btn btn-primary btn-block" type="submit"><?= e(t('login.submit')) ?></button>
            </form>
        </div>
    </div>
</section>
