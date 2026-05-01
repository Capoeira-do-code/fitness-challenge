<?php

declare(strict_types=1);

$loginIconUrl = trim((string) ($loginAppIconUrl ?? ''));
$appName = (string) (app_setting($GLOBALS['pdo'], 'app_name', (string) ($config['app_name'] ?? 'Fitness Challenge Tracker')) ?? 'Fitness Challenge Tracker');
?>
<section class="auth-wrap auth-wrap-login">
    <div class="auth-shell auth-shell-login">
        <div class="auth-copy auth-copy-login">
            <?php if ($loginIconUrl !== ''): ?>
                <img class="login-brand-image" src="<?= e($loginIconUrl) ?>" alt="<?= e($appName) ?>">
            <?php else: ?>
                <span class="brand-mark large"><?= e(initials_for($appName)) ?></span>
            <?php endif; ?>
            <p class="eyebrow"><?= e($appName) ?></p>
            <h1><?= e(t('login.title')) ?></h1>
            <p><?= e(t('login.subtitle')) ?></p>
        </div>

        <div class="panel auth-card auth-card-login">
            <form method="post" action="/?page=login" class="stack">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

                <label>
                    <span><?= e(t('common.username')) ?></span>
                    <input type="text" name="username" autocomplete="username" required>
                </label>

                <label>
                    <span><?= e(t('common.password')) ?></span>
                    <input type="password" name="password" autocomplete="current-password" required>
                </label>

                <button class="btn btn-primary btn-block" type="submit"><?= e(t('login.submit')) ?></button>
            </form>
        </div>
    </div>
</section>
