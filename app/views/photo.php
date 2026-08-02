<?php

declare(strict_types=1);

$photo = is_array($photo ?? null) ? $photo : [];
$comments = is_array($comments ?? null) ? array_values((array) $comments) : [];
$commentCount = count($comments);
$photoId = (int) ($photo['id'] ?? 0);
$photoUrl = media_url((string) ($photo['file_path'] ?? ''));
$photoLogDate = to_date((string) ($photo['log_date'] ?? null));
$photoOwnerId = (int) ($photo['user_id'] ?? 0);
$ownerName = (string) ($photo['display_name'] ?? t('common.user'));
$photoCategory = (string) ($photo['category'] ?? 'other');
$photoCanDelete = (bool) ($canDeletePhoto ?? false);
$photoCanEdit = (bool) ($canEditPhoto ?? false);
$backUrl = '/?page=entries&mode=nutrition&date=' . rawurlencode($photoLogDate);
$categoryLabels = [
    'breakfast' => t('entries.breakfast'),
    'lunch' => t('entries.lunch'),
    'dinner' => t('entries.dinner'),
    'other' => t('common.other'),
];
$categoryLabel = (string) ($categoryLabels[$photoCategory] ?? $photoCategory);
$formatDateTime = static function (?string $value): string {
    $raw = trim((string) $value);
    if ($raw === '') {
        return '';
    }

    try {
        return (new DateTimeImmutable($raw))->format('d/m/Y H:i');
    } catch (Throwable) {
        return $raw;
    }
};
$nutritionFields = [
    'calories' => ['label' => t('entries.photo_calories'), 'unit' => 'kcal', 'decimals' => 0],
    'protein_g' => ['label' => t('entries.photo_protein'), 'unit' => 'g', 'decimals' => 1],
    'carbs_g' => ['label' => t('entries.photo_carbs'), 'unit' => 'g', 'decimals' => 1],
    'fat_g' => ['label' => t('entries.photo_fat'), 'unit' => 'g', 'decimals' => 1],
    'fiber_g' => ['label' => t('entries.photo_fiber'), 'unit' => 'g', 'decimals' => 1],
    'sugar_g' => ['label' => t('entries.photo_sugar'), 'unit' => 'g', 'decimals' => 1],
    'sodium_mg' => ['label' => t('entries.photo_sodium'), 'unit' => 'mg', 'decimals' => 0],
];
$nutritionRows = [];
foreach ($nutritionFields as $field => $meta) {
    $value = $photo[$field] ?? null;
    if ($value === null || $value === '') {
        continue;
    }
    if (!is_numeric($value)) {
        continue;
    }

    $numeric = (float) $value;
    $formatted = number_format($numeric, (int) ($meta['decimals'] ?? 0), '.', '');
    // Only trim trailing zeros out of the fractional part (e.g. "60.20" -> "60.2").
    // Trimming unconditionally also ate trailing zeros from whole numbers with no
    // decimal point at all, turning 650 kcal into "65" and 700 mg into "7".
    if (str_contains($formatted, '.')) {
        $formatted = rtrim(rtrim($formatted, '0'), '.');
    }
    if ($formatted === '') {
        $formatted = '0';
    }
    $nutritionRows[] = [
        'label' => (string) ($meta['label'] ?? $field),
        'value' => $formatted . ' ' . (string) ($meta['unit'] ?? ''),
    ];
}
?>
<section class="screen stack-lg">
    <article class="panel photo-post compact-panel glass-panel">
        <div class="panel-head photo-post-head">
            <div>
                <p class="eyebrow"><?= e(t('common.photo')) ?></p>
                <h1 class="photo-post-title"><?= e(t('photo.title')) ?></h1>
            </div>
            <div class="photo-post-head-actions">
                <a class="hierarchy-back destination-back photo-back-btn" href="<?= e($backUrl) ?>" aria-label="<?= e(t('photo.back_to_entries')) ?>"><span aria-hidden="true">&larr;</span><strong><?= e(t('nav.entries')) ?></strong></a>
                <?php if ($photoCanDelete || $photoCanEdit): ?>
                    <?php
                    $photoDeleteFormId = 'photo-delete-form-page-' . $photoId;
                    $photoMenuItems = [];
                    if ($photoCanEdit) {
                        $photoMenuItems[] = [
                            'label' => t('photo.edit_post'),
                            'attrs' => ['data-photo-edit-open' => ''],
                        ];
                    }
                    if ($photoCanDelete) {
                        $photoMenuItems[] = [
                            'label' => t('photo.delete_photo'),
                            'danger' => true,
                            'attrs' => [
                                'data-photo-delete-trigger' => '',
                                'data-photo-delete-form' => $photoDeleteFormId,
                                'data-photo-delete-message' => t('entries.delete_photo_confirm'),
                            ],
                        ];
                    }
                    echo render_kebab_menu($photoMenuItems, [
                        'label' => t('photo.actions'),
                        'align' => 'end',
                    ]);
                    ?>
                <?php endif; ?>
                <?php if ($photoCanDelete): ?>
                    <form id="<?= e($photoDeleteFormId) ?>" method="post" action="/?page=photo&photo_id=<?= $photoId ?>" hidden>
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="delete_photo">
                        <input type="hidden" name="photo_id" value="<?= $photoId ?>">
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <div class="photo-post-layout">
            <figure class="photo-post-media">
                <?php if ($photoUrl !== ''): ?>
                    <img src="<?= e($photoUrl) ?>" alt="<?= e(t('common.photo')) ?>" loading="eager" fetchpriority="high" decoding="async">
                <?php else: ?>
                    <div class="photo-placeholder">
                        <div class="photo-placeholder-content">
                            <p><?= e(t('photo.no_image')) ?></p>
                        </div>
                    </div>
                <?php endif; ?>
            </figure>

            <div class="photo-post-side stack">
                <article class="mini-card photo-post-meta compact-panel glass-panel">
                    <div class="photo-post-meta-main">
                        <a class="photo-post-author user-profile-link" href="/?page=profile&amp;user_id=<?= $photoOwnerId ?>">
                            <?php
                            $authorForAvatar = [
                                'display_name' => $ownerName,
                                'avatar_path' => (string) ($photo['avatar_path'] ?? ''),
                                'updated_at' => (string) ($photo['user_updated_at'] ?? ''),
                            ];
                            $authorAvatarUrl = avatar_url($authorForAvatar);
                            ?>
                            <?php if ($authorAvatarUrl !== ''): ?>
                                <img class="profile-avatar" src="<?= e($authorAvatarUrl) ?>" alt="<?= e($ownerName) ?>" loading="lazy" decoding="async">
                            <?php else: ?>
                                <span class="profile-avatar initials"><?= e(initials_for($ownerName)) ?></span>
                            <?php endif; ?>
                            <div>
                                <strong><?= e($ownerName) ?></strong>
                                <span><?= e($formatDateTime((string) ($photo['created_at'] ?? ''))) ?></span>
                            </div>
                        </a>
                        <div class="photo-post-tags">
                            <span class="badge"><?= e($categoryLabel) ?></span>
                            <span class="badge"><?= e(format_date_eu($photoLogDate)) ?></span>
                        </div>
                    </div>
                    <div class="photo-post-divider" aria-hidden="true"></div>
                    <?php if (!empty($photo['caption'])): ?>
                        <p class="photo-post-caption"><?= e((string) $photo['caption']) ?></p>
                    <?php endif; ?>
                </article>

                <?php if ($nutritionRows !== []): ?>
                    <article class="mini-card photo-post-nutrition compact-panel glass-panel">
                        <h3><?= e(t('photo.nutrition')) ?></h3>
                        <ul>
                            <?php foreach ($nutritionRows as $row): ?>
                                <li>
                                    <strong><?= e((string) $row['label']) ?></strong>
                                    <span><?= e((string) $row['value']) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </article>
                <?php endif; ?>
            </div>
        </div>

        <article class="photo-comments stack" data-social-comment-region>
            <div class="panel-head photo-comments-head">
                <h2><?= e(t('photo.comments')) ?></h2>
                <span class="badge<?= $commentCount > 0 ? '' : ' is-empty' ?>" data-social-comment-count><?= $commentCount ?></span>
            </div>

            <form method="post" action="/?page=photo&photo_id=<?= $photoId ?>" class="social-comment-composer photo-comment-form" data-social-comment-form data-allow-multi-submit>
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="social_feed_comment">
                <input type="hidden" name="entity_type" value="photo">
                <input type="hidden" name="entity_id" value="<?= $photoId ?>">
                <label><span class="sr-only"><?= e(t('photo.add_comment')) ?></span><input type="text" name="comment" maxlength="1200" placeholder="<?= e(t('photo.comment_placeholder')) ?>" required></label>
                <button type="submit" class="btn btn-primary" aria-label="<?= e(t('photo.comment_submit')) ?>"><?= activity_icon_svg('send') ?><span><?= e(t('photo.comment_submit')) ?></span></button>
            </form>

            <?= social_comment_thread_html($comments, $currentUser, $photoOwnerId, '/?page=photo&photo_id=' . $photoId, 'photo', $photoId) ?>
        </article>
    </article>
</section>

<div class="confirm-modal" hidden aria-hidden="true" data-photo-delete-modal>
    <div class="confirm-modal-backdrop" data-photo-delete-cancel></div>
    <div class="confirm-modal-card" role="dialog" aria-modal="true" aria-labelledby="photo-delete-title">
        <h3 id="photo-delete-title"><?= e(t('entries.delete_photo_confirm')) ?></h3>
        <div class="confirm-modal-actions">
            <button type="button" class="btn btn-ghost" data-photo-delete-cancel><?= e(t('common.cancel')) ?></button>
            <button type="button" class="btn btn-primary" data-photo-delete-confirm><?= e(t('common.delete')) ?></button>
        </div>
    </div>
</div>

<?php if ($photoCanEdit): ?>
<div class="confirm-modal" hidden aria-hidden="true" data-photo-edit-modal>
    <div class="confirm-modal-backdrop" data-photo-edit-close></div>
    <div class="confirm-modal-card photo-edit-modal-card" role="dialog" aria-modal="true" aria-labelledby="photo-edit-title">
        <h3 id="photo-edit-title"><?= e(t('photo.edit_post')) ?></h3>
        <form method="post" action="/?page=photo&photo_id=<?= $photoId ?>" enctype="multipart/form-data" class="stack compact-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="update_photo">
            <input type="hidden" name="photo_id" value="<?= $photoId ?>">

            <div class="grid-inline entries-two-col">
                <label>
                    <?= e(t('common.date')) ?>
                    <input type="date" name="log_date" value="<?= e($photoLogDate) ?>" required>
                </label>
                <label>
                    <?= e(t('common.category')) ?>
                    <select name="category">
                        <option value="breakfast" <?= $photoCategory === 'breakfast' ? 'selected' : '' ?>><?= e(t('entries.breakfast')) ?></option>
                        <option value="lunch" <?= $photoCategory === 'lunch' ? 'selected' : '' ?>><?= e(t('entries.lunch')) ?></option>
                        <option value="dinner" <?= $photoCategory === 'dinner' ? 'selected' : '' ?>><?= e(t('entries.dinner')) ?></option>
                        <option value="other" <?= $photoCategory === 'other' ? 'selected' : '' ?>><?= e(t('common.other')) ?></option>
                    </select>
                </label>
            </div>

            <label>
                <?= e(t('common.caption')) ?>
                <input type="text" name="caption" value="<?= e((string) ($photo['caption'] ?? '')) ?>" placeholder="<?= e(t('entries.caption_placeholder')) ?>">
            </label>

            <label>
                <?= e(t('photo.replace_photo')) ?>
                <input type="file" name="photo" accept="image/jpeg,image/png,image/webp,image/heic,image/heif,image/gif">
                <small class="muted"><?= e(t('photo.replace_photo_hint')) ?></small>
            </label>

            <div class="grid-inline entries-two-col">
                <label>
                    <?= e(t('entries.photo_calories')) ?>
                    <input type="number" min="0" step="1" name="photo_calories" value="<?= e((string) ($photo['calories'] ?? '')) ?>" placeholder="650">
                </label>
                <label>
                    <?= e(t('entries.photo_protein')) ?>
                    <input type="number" min="0" step="0.1" name="photo_protein_g" value="<?= e((string) ($photo['protein_g'] ?? '')) ?>" placeholder="35">
                </label>
                <label>
                    <?= e(t('entries.photo_carbs')) ?>
                    <input type="number" min="0" step="0.1" name="photo_carbs_g" value="<?= e((string) ($photo['carbs_g'] ?? '')) ?>" placeholder="60">
                </label>
                <label>
                    <?= e(t('entries.photo_fat')) ?>
                    <input type="number" min="0" step="0.1" name="photo_fat_g" value="<?= e((string) ($photo['fat_g'] ?? '')) ?>" placeholder="22">
                </label>
                <label>
                    <?= e(t('entries.photo_fiber')) ?>
                    <input type="number" min="0" step="0.1" name="photo_fiber_g" value="<?= e((string) ($photo['fiber_g'] ?? '')) ?>" placeholder="8">
                </label>
                <label>
                    <?= e(t('entries.photo_sugar')) ?>
                    <input type="number" min="0" step="0.1" name="photo_sugar_g" value="<?= e((string) ($photo['sugar_g'] ?? '')) ?>" placeholder="12">
                </label>
                <label>
                    <?= e(t('entries.photo_sodium')) ?>
                    <input type="number" min="0" step="1" name="photo_sodium_mg" value="<?= e((string) ($photo['sodium_mg'] ?? '')) ?>" placeholder="700">
                </label>
            </div>

            <div class="confirm-modal-actions">
                <button class="btn btn-ghost" type="button" data-photo-edit-close><?= e(t('common.cancel')) ?></button>
                <button class="btn btn-primary" type="submit"><?= e(t('common.save')) ?></button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
