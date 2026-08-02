<?php

declare(strict_types=1);

$threadComments = array_values((array) ($socialComments ?? []));
$threadUser = is_array($socialCommentCurrentUser ?? null) ? $socialCommentCurrentUser : [];
$threadUserId = (int) ($threadUser['id'] ?? 0);
$threadOwnerId = (int) ($socialCommentOwnerId ?? 0);
$threadEndpoint = (string) ($socialCommentEndpoint ?? '/?page=dashboard');
$threadType = social_feed_entity_type((string) ($socialCommentEntityType ?? ''));
$threadEntityId = (int) ($socialCommentEntityId ?? 0);
$threadScope = (string) ($socialCommentFeedScope ?? '');
$topLevel = [];
$repliesByParent = [];
foreach ($threadComments as $threadComment) {
    $parentId = (int) ($threadComment['parent_comment_id'] ?? 0);
    if ($parentId > 0) {
        $repliesByParent[$parentId][] = $threadComment;
    } else {
        $topLevel[] = $threadComment;
    }
}
$renderComment = static function (array $comment, bool $isReply = false) use (
    $threadUser,
    $threadUserId,
    $threadOwnerId,
    $threadEndpoint,
    $threadType,
    $threadEntityId,
    $threadScope
): void {
    $commentId = (int) ($comment['id'] ?? 0);
    $authorId = (int) ($comment['user_id'] ?? 0);
    $author = trim((string) ($comment['display_name'] ?? $comment['username'] ?? t('common.user')));
    $canEdit = $authorId > 0 && $authorId === $threadUserId;
    $canDelete = is_admin($threadUser) || $canEdit || ($threadOwnerId > 0 && $threadOwnerId === $threadUserId);
    $avatar = avatar_url([
        'display_name' => $author,
        'avatar_path' => (string) ($comment['avatar_path'] ?? ''),
        'updated_at' => (string) ($comment['user_updated_at'] ?? ''),
    ]);
    $createdAt = (string) ($comment['created_at'] ?? '');
    $updatedAt = (string) ($comment['updated_at'] ?? '');
    $isEdited = $createdAt !== '' && $updatedAt !== '' && $updatedAt !== $createdAt;
    $replyFormId = 'social-comment-reply-' . $threadType . '-' . $threadEntityId . '-' . $commentId;
    $editFormId = 'social-comment-edit-' . $threadType . '-' . $threadEntityId . '-' . $commentId;
    ?>
    <article class="social-comment<?= $isReply ? ' is-reply' : '' ?>" id="comment-<?= e($threadType) ?>-<?= $commentId ?>" data-social-comment="<?= $commentId ?>">
        <header class="social-comment-head">
            <a class="social-comment-author user-profile-link" href="/?page=profile&amp;user_id=<?= $authorId ?>">
                <?php if ($avatar !== ''): ?>
                    <img src="<?= e($avatar) ?>" alt="" loading="lazy" decoding="async">
                <?php else: ?>
                    <span aria-hidden="true"><?= e(initials_for($author)) ?></span>
                <?php endif; ?>
                <strong><?= e($author) ?></strong>
            </a>
            <time datetime="<?= e($createdAt) ?>"><?= e(human_time_ago($createdAt)) ?><?= $isEdited ? ' · ' . e(t('feed.edited')) : '' ?></time>
        </header>
        <p class="social-comment-body"><?= nl2br(e((string) ($comment['comment'] ?? ''))) ?></p>
        <div class="social-comment-actions">
            <button type="button" data-social-comment-reply-toggle aria-expanded="false" aria-controls="<?= e($replyFormId) ?>"><?= e(t('feed.reply')) ?></button>
            <?php if ($canEdit): ?>
                <span aria-hidden="true">·</span>
                <button type="button" data-social-comment-edit-toggle aria-expanded="false" aria-controls="<?= e($editFormId) ?>"><?= e(t('common.edit')) ?></button>
            <?php endif; ?>
            <?php if ($canDelete): ?>
                <span aria-hidden="true">·</span>
                <form method="post" action="<?= e($threadEndpoint) ?>" data-social-comment-form data-social-comment-delete data-confirm-message="<?= e(t('feed.delete_comment_confirm')) ?>" data-allow-multi-submit>
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="social_feed_comment_delete">
                    <input type="hidden" name="entity_type" value="<?= e($threadType) ?>">
                    <input type="hidden" name="entity_id" value="<?= $threadEntityId ?>">
                    <input type="hidden" name="comment_id" value="<?= $commentId ?>">
                    <?php if ($threadScope !== ''): ?><input type="hidden" name="feed_scope" value="<?= e($threadScope) ?>"><?php endif; ?>
                    <button type="submit"><?= e(t('common.delete')) ?></button>
                </form>
            <?php endif; ?>
        </div>
        <form id="<?= e($replyFormId) ?>" method="post" action="<?= e($threadEndpoint) ?>" class="social-comment-composer is-inline" data-social-comment-form hidden data-allow-multi-submit>
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="social_feed_comment">
            <input type="hidden" name="entity_type" value="<?= e($threadType) ?>">
            <input type="hidden" name="entity_id" value="<?= $threadEntityId ?>">
            <input type="hidden" name="parent_comment_id" value="<?= $commentId ?>">
            <?php if ($threadScope !== ''): ?><input type="hidden" name="feed_scope" value="<?= e($threadScope) ?>"><?php endif; ?>
            <label><span class="sr-only"><?= e(t('feed.reply_to', ['name' => $author])) ?></span><textarea name="comment" rows="2" maxlength="1200" required placeholder="<?= e(t('feed.reply_placeholder', ['name' => $author])) ?>"></textarea></label>
            <div><button type="button" class="btn btn-ghost small" data-social-comment-cancel><?= e(t('common.cancel')) ?></button><button type="submit" class="btn btn-primary small"><?= e(t('feed.send_reply')) ?></button></div>
        </form>
        <?php if ($canEdit): ?>
            <form id="<?= e($editFormId) ?>" method="post" action="<?= e($threadEndpoint) ?>" class="social-comment-composer is-inline" data-social-comment-form hidden data-allow-multi-submit>
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="social_feed_comment_edit">
                <input type="hidden" name="entity_type" value="<?= e($threadType) ?>">
                <input type="hidden" name="entity_id" value="<?= $threadEntityId ?>">
                <input type="hidden" name="comment_id" value="<?= $commentId ?>">
                <?php if ($threadScope !== ''): ?><input type="hidden" name="feed_scope" value="<?= e($threadScope) ?>"><?php endif; ?>
                <label><span class="sr-only"><?= e(t('feed.edit_comment')) ?></span><textarea name="comment" rows="2" maxlength="1200" required><?= e((string) ($comment['comment'] ?? '')) ?></textarea></label>
                <div><button type="button" class="btn btn-ghost small" data-social-comment-cancel><?= e(t('common.cancel')) ?></button><button type="submit" class="btn btn-primary small"><?= e(t('common.save')) ?></button></div>
            </form>
        <?php endif; ?>
    </article>
    <?php
};
?>
<div class="social-comment-list" data-social-comment-list>
    <?php if ($topLevel === []): ?>
        <div class="social-comment-empty" data-social-comment-empty><strong><?= e(t('photo.comments_empty')) ?></strong><span><?= e(t('photo.comments_empty_hint')) ?></span></div>
    <?php else: ?>
        <?php foreach ($topLevel as $comment): ?>
            <?php $renderComment($comment, false); ?>
            <?php $parentId = (int) ($comment['id'] ?? 0); $threadReplies = $repliesByParent[$parentId] ?? []; ?>
            <?php if ($threadReplies !== []): ?>
                <div class="social-comment-replies" aria-label="<?= e(t('feed.replies')) ?>">
                    <?php foreach ($threadReplies as $reply): $renderComment($reply, true); endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
