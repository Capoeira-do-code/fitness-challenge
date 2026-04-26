<?php

declare(strict_types=1);
?>
<section class="auth-wrap">
    <div class="auth-shell">
        <div class="auth-copy">
            <span class="brand-mark large">FC</span>
            <p class="eyebrow"><?= e(t('app.short_name')) ?></p>
            <h1><?= e(t('login.title')) ?></h1>
            <p><?= e(t('login.subtitle')) ?></p>
        </div>

        <div class="panel auth-card">
            <form method="post" action="/?page=login" class="stack">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

                <label>
                    <?= e(t('common.username')) ?>
                    <input type="text" name="username" autocomplete="username" required>
                </label>

                <label>
                    <?= e(t('common.password')) ?>
                    <input type="password" name="password" autocomplete="current-password" required>
                </label>

                <button class="btn btn-primary btn-block" type="submit"><?= e(t('login.submit')) ?></button>
            </form>

            <div class="auth-footnote">
                <strong><?= e(t('login.seed_title')) ?></strong><br>
                <?= e(t('login.seed_detail')) ?>
            </div>
        </div>
    </div>
</section>
