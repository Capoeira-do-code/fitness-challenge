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
$backUrl = '/?page=entries&mode=meal&date=' . rawurlencode($photoLogDate);
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
    $formatted = rtrim(rtrim($formatted, '0'), '.');
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
    <article class="panel photo-post">
        <div class="panel-head photo-post-head">
            <div>
                <p class="eyebrow"><?= e(t('common.photo')) ?></p>
                <h1 class="photo-post-title"><?= e(t('photo.title')) ?></h1>
            </div>
            <div class="photo-post-head-actions">
                <a class="btn btn-ghost small photo-back-btn" href="<?= e($backUrl) ?>">&larr; <?= e(t('photo.back_to_entries')) ?></a>
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
                    <img src="<?= e($photoUrl) ?>" alt="<?= e(t('common.photo')) ?>">
                <?php else: ?>
                    <div class="photo-placeholder">
                        <div class="photo-placeholder-content">
                            <p><?= e(t('photo.no_image')) ?></p>
                        </div>
                    </div>
                <?php endif; ?>
            </figure>

            <div class="photo-post-side stack">
                <article class="mini-card photo-post-meta">
                    <div class="photo-post-meta-main">
                        <div class="photo-post-author">
                            <?php
                            $authorForAvatar = [
                                'display_name' => $ownerName,
                                'avatar_path' => (string) ($photo['avatar_path'] ?? ''),
                                'updated_at' => (string) ($photo['user_updated_at'] ?? ''),
                            ];
                            $authorAvatarUrl = avatar_url($authorForAvatar);
                            ?>
                            <?php if ($authorAvatarUrl !== ''): ?>
                                <img class="profile-avatar" src="<?= e($authorAvatarUrl) ?>" alt="<?= e($ownerName) ?>">
                            <?php else: ?>
                                <span class="profile-avatar initials"><?= e(initials_for($ownerName)) ?></span>
                            <?php endif; ?>
                            <div>
                                <strong><?= e($ownerName) ?></strong>
                                <span><?= e($formatDateTime((string) ($photo['created_at'] ?? ''))) ?></span>
                            </div>
                        </div>
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
                    <article class="mini-card photo-post-nutrition">
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

        <article class="photo-comments stack">
            <div class="panel-head photo-comments-head">
                <h2><?= e(t('photo.comments')) ?></h2>
                <?php if ($commentCount > 0): ?>
                    <span class="badge"><?= $commentCount ?></span>
                <?php endif; ?>
            </div>

            <form method="post" action="/?page=photo&photo_id=<?= $photoId ?>" class="stack photo-comment-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="add_photo_comment">
                <input type="hidden" name="photo_id" value="<?= $photoId ?>">
                <label>
                    <?= e(t('photo.add_comment')) ?>
                    <input type="text" name="comment" maxlength="1200" placeholder="<?= e(t('photo.comment_placeholder')) ?>" required>
                </label>
                <div class="inline-actions photo-comment-actions">
                    <button type="submit" class="btn btn-primary"><?= e(t('photo.comment_submit')) ?></button>
                </div>
            </form>

            <?php if ($comments === []): ?>
                <div class="photo-comments-empty">
                    <strong><?= e(t('photo.comments_empty')) ?></strong>
                    <span><?= e(t('photo.comments_empty_hint')) ?></span>
                </div>
            <?php else: ?>
                <div class="photo-comment-list">
                    <?php foreach ($comments as $comment): ?>
                        <?php
                        $commentAuthor = (string) ($comment['display_name'] ?? t('common.user'));
                        $commentCanDelete = is_admin($currentUser)
                            || (int) ($comment['user_id'] ?? 0) === (int) ($currentUser['id'] ?? 0)
                            || $photoOwnerId === (int) ($currentUser['id'] ?? 0);
                        $commentAvatarInput = [
                            'display_name' => $commentAuthor,
                            'avatar_path' => (string) ($comment['avatar_path'] ?? ''),
                            'updated_at' => (string) ($comment['user_updated_at'] ?? ''),
                        ];
                        $commentAvatarUrl = avatar_url($commentAvatarInput);
                        ?>
                        <article class="photo-comment-item">
                            <div class="photo-comment-head">
                                <div class="photo-comment-author">
                                    <?php if ($commentAvatarUrl !== ''): ?>
                                        <img class="profile-avatar" src="<?= e($commentAvatarUrl) ?>" alt="<?= e($commentAuthor) ?>">
                                    <?php else: ?>
                                        <span class="profile-avatar initials"><?= e(initials_for($commentAuthor)) ?></span>
                                    <?php endif; ?>
                                    <div>
                                        <strong><?= e($commentAuthor) ?></strong>
                                        <span><?= e($formatDateTime((string) ($comment['created_at'] ?? ''))) ?></span>
                                    </div>
                                </div>
                                <?php if ($commentCanDelete): ?>
                                    <form method="post" action="/?page=photo&photo_id=<?= $photoId ?>">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="delete_photo_comment">
                                        <input type="hidden" name="photo_id" value="<?= $photoId ?>">
                                        <input type="hidden" name="comment_id" value="<?= (int) ($comment['id'] ?? 0) ?>">
                                        <button type="submit" class="btn btn-ghost small"><?= e(t('photo.delete_comment')) ?></button>
                                    </form>
                                <?php endif; ?>
                            </div>
                            <p><?= nl2br(e((string) ($comment['comment'] ?? ''))) ?></p>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
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
