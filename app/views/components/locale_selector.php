<?php

declare(strict_types=1);

$localeScope = $localeScope ?? 'default';
$localeFormClass = $localeFormClass ?? 'locale-form';
$localeSelectId = $localeSelectId ?? 'locale-select';
$localeRedirectTo = $localeRedirectTo ?? '/';
$localeShowSaveButton = (bool) ($localeShowSaveButton ?? false);
$localeAsync = (bool) ($localeAsync ?? false);
$localeLabel = $localeLabel ?? t('common.language');
?>
<form
    method="post"
    action="/?page=set_locale"
    class="<?= e($localeFormClass) ?>"
    data-locale-selector="<?= e($localeScope) ?>"
    <?= $localeAsync ? 'data-locale-async="1"' : '' ?>
>
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="redirect_to" value="<?= e($localeRedirectTo) ?>">
    <?php if ($localeAsync): ?><input type="hidden" name="async" value="1"><?php endif; ?>
    <label class="sr-only" for="<?= e($localeSelectId) ?>"><?= e($localeLabel) ?></label>
    <select id="<?= e($localeSelectId) ?>" name="locale" <?= $localeAsync ? '' : 'onchange="this.form.submit()"' ?>>
        <?php foreach (locale_options() as $locale => $label): ?>
            <option value="<?= e($locale) ?>" <?= $locale === current_locale() ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
    </select>
    <?php if ($localeShowSaveButton): ?>
        <button class="btn btn-primary" type="submit"><?= e(t('common.save')) ?></button>
    <?php endif; ?>
    <?php if ($localeAsync): ?>
        <noscript><button class="btn btn-ghost small" type="submit"><?= e(t('common.save')) ?></button></noscript>
    <?php endif; ?>
</form>
