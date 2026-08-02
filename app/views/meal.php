<?php

declare(strict_types=1);

$meal = is_array($mealEntry ?? null) ? $mealEntry : null;
$backUrl = trim((string) ($mealBackUrl ?? ''));
if ($backUrl === '') {
    $backUrl = '/?page=dashboard&home=feed&feed=friends#home-social-feed';
}
$formatValue = static function (mixed $value, int $decimals = 1): string {
    if ($value === null || $value === '') {
        return '—';
    }
    $formatted = number_format((float) $value, $decimals, ',', '.');
    return $decimals > 0 ? rtrim(rtrim($formatted, '0'), ',') : $formatted;
};
?>
<section class="screen stack-lg nutrition-public-meal-screen">
    <article class="panel nutrition-public-meal compact-panel glass-panel">
        <header class="nutrition-public-meal-head">
            <a class="hierarchy-back destination-back nutrition-public-meal-back" href="<?= e($backUrl) ?>" aria-label="<?= e(t('common.back')) ?>: <?= e(t('feed.title')) ?>">
                <span aria-hidden="true"><?= activity_icon_svg('chevron-left') ?></span><strong><?= e(t('feed.title')) ?></strong>
            </a>
            <div><p class="eyebrow"><?= e(t('feed.meal_update')) ?></p><h1><?= e(t('nutrition.meal_details')) ?></h1></div>
        </header>

        <?php if ($meal === null): ?>
            <div class="nutrition-public-meal-empty empty-state">
                <span aria-hidden="true"><?= activity_icon_svg('info') ?></span>
                <h2><?= e(t('nutrition.meal_unavailable')) ?></h2>
                <p><?= e(t('nutrition.meal_unavailable_hint')) ?></p>
                <a class="btn btn-primary" href="<?= e($backUrl) ?>"><?= e(t('nutrition.back_to_feed')) ?></a>
            </div>
        <?php else: ?>
            <?php
            $ownerId = (int) ($meal['user_id'] ?? 0);
            $ownerName = trim((string) ($meal['display_name'] ?? $meal['username'] ?? t('common.user')));
            $ownerAvatar = avatar_url($meal);
            $mealType = (string) ($meal['meal_type'] ?? 'other');
            $mealTypeLabel = t('nutrition.type_' . $mealType);
            $mealNotes = trim((string) ($meal['notes'] ?? ''));
            $mealPhotoPath = trim((string) ($meal['photo_path'] ?? ''));
            $mealDate = format_date_eu((string) ($meal['entry_date'] ?? ''));
            $mealTime = trim((string) ($meal['entry_time'] ?? ''));
            $viewerOwnsMeal = (int) ($currentUser['id'] ?? 0) === $ownerId;
            ?>
            <div class="nutrition-public-meal-layout<?= $mealPhotoPath === '' ? ' has-no-photo' : '' ?>">
                <?php if ($mealPhotoPath !== ''): ?>
                    <figure class="nutrition-public-meal-photo">
                        <img src="<?= e(media_url($mealPhotoPath)) ?>" alt="<?= e($mealTypeLabel) ?>" loading="eager" fetchpriority="high" decoding="async">
                    </figure>
                <?php endif; ?>
                <div class="nutrition-public-meal-content">
                    <a class="nutrition-public-meal-author" href="/?page=profile&amp;user_id=<?= $ownerId ?>">
                        <span class="profile-avatar" aria-hidden="true"><?php if ($ownerAvatar !== ''): ?><img src="<?= e($ownerAvatar) ?>" alt="" loading="lazy"><?php else: ?><?= e(initials_for($ownerName)) ?><?php endif; ?></span>
                        <span><strong><?= e($ownerName) ?></strong><small><?= e($mealDate) ?><?= $mealTime !== '' ? ' · ' . e($mealTime) : '' ?></small></span>
                        <b aria-hidden="true">&rsaquo;</b>
                    </a>

                    <div class="nutrition-public-meal-title">
                        <span aria-hidden="true"><?= activity_icon_svg('flame') ?></span>
                        <div><small><?= e(t('nutrition.meal_type')) ?></small><h2><?= e($mealTypeLabel) ?></h2></div>
                        <strong><?= e($formatValue($meal['calories'] ?? 0, 0)) ?><small>kcal</small></strong>
                    </div>

                    <dl class="nutrition-entry-detail-grid nutrition-public-meal-grid">
                        <div><dt><?= e(t('nutrition.protein')) ?></dt><dd><?= e($formatValue($meal['protein_g'] ?? null)) ?> g</dd></div>
                        <div><dt><?= e(t('nutrition.carbs')) ?></dt><dd><?= e($formatValue($meal['carbs_g'] ?? null)) ?> g</dd></div>
                        <div><dt><?= e(t('nutrition.fat')) ?></dt><dd><?= e($formatValue($meal['fat_g'] ?? null)) ?> g</dd></div>
                        <div><dt><?= e(t('nutrition.fiber')) ?></dt><dd><?= e($formatValue($meal['fiber_g'] ?? null)) ?> g</dd></div>
                        <div><dt><?= e(t('nutrition.sugar')) ?></dt><dd><?= e($formatValue($meal['sugar_g'] ?? null)) ?> g</dd></div>
                        <div><dt><?= e(t('nutrition.sodium')) ?></dt><dd><?= e($formatValue($meal['sodium_mg'] ?? null, 0)) ?> mg</dd></div>
                    </dl>

                    <?php if ($mealNotes !== ''): ?>
                        <div class="nutrition-entry-detail-notes nutrition-public-meal-notes"><strong><?= e(t('nutrition.notes')) ?></strong><p><?= nl2br(e($mealNotes)) ?></p></div>
                    <?php endif; ?>
                    <div class="nutrition-public-meal-footer">
                        <p class="nutrition-public-meal-readonly"><span aria-hidden="true"><?= activity_icon_svg('shield') ?></span><?= e(t('nutrition.shared_read_only')) ?></p>
                        <?php if ($viewerOwnsMeal): ?><a class="btn btn-ghost small" href="/?page=nutrition&amp;date=<?= e(rawurlencode((string) ($meal['entry_date'] ?? ''))) ?>&amp;meal_id=<?= (int) ($meal['id'] ?? 0) ?>"><?= e(t('nutrition.open_workspace')) ?></a><?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </article>
</section>
