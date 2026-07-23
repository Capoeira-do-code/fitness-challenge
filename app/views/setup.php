<?php

declare(strict_types=1);

$values = is_array($setupValues ?? null) ? $setupValues : [];
?>
<section class="auth-wrap setup-wrap">
    <div class="panel auth-card setup-card">
        <div class="auth-card-header">
            <span class="brand-mark login-card-logo-mark"><?= e(initials_for((string) ($values['app_name'] ?? 'FC'))) ?></span>
            <p class="eyebrow">Nueva instalación</p>
            <h1 class="auth-card-title">Configura tu sitio</h1>
            <p class="auth-card-subtitle">Crea el primer administrador y define los datos iniciales. Podrás cambiarlos después desde Administración.</p>
        </div>

        <?php if (($setupError ?? '') !== ''): ?>
            <div class="alert error" role="alert"><?= e((string) $setupError) ?></div>
        <?php endif; ?>

        <form method="post" action="/?page=setup" class="stack setup-form" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

            <fieldset>
                <legend>Ajustes del sitio</legend>
                <div class="setup-grid">
                    <label><span>Nombre de la aplicación</span><input type="text" name="app_name" maxlength="80" value="<?= e((string) ($values['app_name'] ?? '')) ?>" required></label>
                    <label><span>Idioma principal</span>
                        <select name="locale">
                            <?php foreach (locale_options() as $code => $label): ?>
                                <option value="<?= e($code) ?>" <?= ($values['locale'] ?? '') === $code ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label><span>Nombre del reto</span><input type="text" name="challenge_name" maxlength="100" value="<?= e((string) ($values['challenge_name'] ?? '')) ?>" required></label>
                    <label><span>Nombre del equipo</span><input type="text" name="team_name" maxlength="100" value="<?= e((string) ($values['team_name'] ?? '')) ?>" required></label>
                    <label><span>Inicio del reto</span><input type="date" name="challenge_start" value="<?= e((string) ($values['challenge_start'] ?? '')) ?>" required></label>
                    <label><span>Final del reto</span><input type="date" name="challenge_end" value="<?= e((string) ($values['challenge_end'] ?? '')) ?>" required></label>
                </div>
            </fieldset>

            <fieldset>
                <legend>Cuenta administradora</legend>
                <div class="setup-grid">
                    <label><span>Nombre visible</span><input type="text" name="display_name" maxlength="80" value="<?= e((string) ($values['display_name'] ?? '')) ?>" autocomplete="name" required autofocus></label>
                    <label><span>Usuario</span><input type="text" name="username" minlength="3" maxlength="40" pattern="[A-Za-z0-9._-]+" value="<?= e((string) ($values['username'] ?? 'admin')) ?>" autocomplete="username" required></label>
                    <label><span>Contraseña</span><input type="password" name="password" minlength="10" autocomplete="new-password" required><small>Mínimo 10 caracteres, con una letra y un número.</small></label>
                    <label><span>Repetir contraseña</span><input type="password" name="password_confirm" minlength="10" autocomplete="new-password" required></label>
                </div>
            </fieldset>

            <button class="btn btn-primary btn-block" type="submit">Completar instalación</button>
        </form>
    </div>
</section>
