<?php

declare(strict_types=1);

$photo = is_array($photo ?? null) ? $photo : [];
$comments = is_array($comments ?? null) ? array_values((array) $comments) : [];
$photoId = (int) ($photo['id'] ?? 0);
$photoUrl = media_url((string) ($photo['file_path'] ?? ''));
$photoLogDate = to_date((string) ($photo['log_date'] ?? null));
$photoOwnerId = (int) ($photo['user_id'] ?? 0);
$ownerName = (string) ($photo['display_name'] ?? t('common.user'));
$photoCategory = (string) ($photo['category'] ?? 'other');
$photoCanDelete = (bool) ($canDeletePhoto ?? false);
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
            <a class="btn btn-ghost small photo-back-btn" href="<?= e($backUrl) ?>">← <?= e(t('photo.back_to_entries')) ?></a>
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
                    <?php if ($photoCanDelete): ?>
                        <div class="photo-post-actions">
                            <h3><?= e(t('photo.actions')) ?></h3>
                            <?php $photoDeleteFormId = 'photo-delete-form-page-' . $photoId; ?>
                            <button
                                type="button"
                                class="btn btn-ghost photo-delete-text-btn"
                                data-photo-delete-trigger
                                data-photo-delete-form="<?= e($photoDeleteFormId) ?>"
                                data-photo-delete-message="<?= e(t('entries.delete_photo_confirm')) ?>"
                            ><?= e(t('photo.delete_photo')) ?></button>
                            <form id="<?= e($photoDeleteFormId) ?>" method="post" action="/?page=photo&photo_id=<?= $photoId ?>" hidden>
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="delete_photo">
                                <input type="hidden" name="photo_id" value="<?= $photoId ?>">
                            </form>
                        </div>
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
                <span class="badge"><?= count($comments) ?></span>
            </div>

            <form method="post" action="/?page=photo&photo_id=<?= $photoId ?>" class="stack photo-comment-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="add_photo_comment">
                <input type="hidden" name="photo_id" value="<?= $photoId ?>">
                <label>
                    <?= e(t('photo.add_comment')) ?>
                    <textarea name="comment" rows="3" maxlength="1200" placeholder="<?= e(t('photo.comment_placeholder')) ?>" required></textarea>
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
