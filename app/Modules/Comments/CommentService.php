<?php

declare(strict_types=1);

namespace PlainCMS\Modules\Comments;

use PlainCMS\Core\Cache\CacheInterface;
use PlainCMS\Core\SettingsService;
use RuntimeException;

final class CommentService
{
    public function __construct(
        private readonly CommentRepository $comments,
        private readonly SettingsService $settings,
        private readonly CacheInterface $cache
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function adminList(): array
    {
        return $this->comments->adminList();
    }

    public function submit(array $page, array $input, string $ipAddress, string $userAgent): string
    {
        if (!$this->commentsEnabledForPage($page)) {
            throw new RuntimeException('Comments are disabled for this page.');
        }

        $name = trim((string) ($input['author_name'] ?? ''));
        $email = trim((string) ($input['author_email'] ?? ''));
        $url = trim((string) ($input['author_url'] ?? ''));
        $content = trim((string) ($input['content'] ?? ''));

        if ($name === '' || $content === '') {
            throw new RuntimeException('Name and comment are required.');
        }

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('Email address is invalid.');
        }

        if ($url !== '' && !preg_match('#^https?://#i', $url)) {
            throw new RuntimeException('Website URL must start with http:// or https://.');
        }

        $moderationRequired = $this->moderationRequired();
        $status = $moderationRequired ? 'pending' : 'approved';

        $this->comments->create([
            'page_id' => (int) $page['id'],
            'parent_id' => null,
            'author_name' => $name,
            'author_email' => $email !== '' ? $email : null,
            'author_url' => $url !== '' ? $url : null,
            'content' => $content,
            'status' => $status,
            'ip_hash' => sha1($ipAddress),
            'user_agent_hash' => sha1($userAgent),
        ]);

        $this->syncCounts((int) $page['id']);
        $this->invalidate();

        return $moderationRequired
            ? 'Comment submitted for moderation.'
            : 'Comment published.';
    }

    public function approve(int $id): void
    {
        $comment = $this->requireComment($id);
        $this->comments->setStatus($id, 'approved');
        $this->syncCounts((int) $comment['page_id']);
        $this->invalidate();
    }

    public function spam(int $id): void
    {
        $comment = $this->requireComment($id);
        $this->comments->setStatus($id, 'spam');
        $this->syncCounts((int) $comment['page_id']);
        $this->invalidate();
    }

    public function delete(int $id): void
    {
        $comment = $this->requireComment($id);
        $this->comments->delete($id);
        $this->syncCounts((int) $comment['page_id']);
        $this->invalidate();
    }

    /**
     * @param array<string, mixed> $page
     */
    public function renderForPage(array $page, string $action, string $csrfInput, string $csrfToken): string
    {
        if (!$this->commentsEnabledForPage($page)) {
            return '';
        }

        $comments = $this->comments->approvedForPage((int) $page['id']);
        $listHtml = '';

        foreach ($comments as $comment) {
            $author = htmlspecialchars((string) $comment['author_name'], ENT_QUOTES, 'UTF-8');
            $content = nl2br(htmlspecialchars((string) $comment['content'], ENT_QUOTES, 'UTF-8'), false);
            $meta = htmlspecialchars((string) ($comment['created_at'] ?? ''), ENT_QUOTES, 'UTF-8');
            $authorHtml = $author;

            if (is_string($comment['author_url']) && trim($comment['author_url']) !== '') {
                $authorHtml = '<a href="' . htmlspecialchars((string) $comment['author_url'], ENT_QUOTES, 'UTF-8') . '">' . $author . '</a>';
            }

            $listHtml .= '<article class="comment-card"><p class="comment-meta">' . $authorHtml . ' · ' . $meta . '</p><div class="comment-content">' . $content . '</div></article>';
        }

        if ($listHtml === '') {
            $listHtml = '<p class="empty-state">No comments yet. Start the conversation.</p>';
        }

        $helpText = $this->moderationRequired()
            ? '<p class="module-copy">Comments are moderated before they appear publicly.</p>'
            : '<p class="module-copy">Comments appear immediately after submission.</p>';

        return '<section id="comments" class="module-card comments-shell"><div class="module-head"><p class="module-kicker">Discussion</p><h2 class="module-title">Comments</h2>' . $helpText . '</div><div class="comment-list">' . $listHtml . '</div>'
            . '<form method="post" action="' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8') . '" class="comment-form">'
            . '<input type="hidden" name="' . htmlspecialchars($csrfInput, ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') . '">'
            . '<div class="public-form-row"><label for="author_name">Name</label><input id="author_name" name="author_name" required></div>'
            . '<div class="public-form-row"><label for="author_email">Email</label><input id="author_email" name="author_email" type="email"></div>'
            . '<div class="public-form-row"><label for="author_url">Website</label><input id="author_url" name="author_url" type="url" placeholder="https://example.com"></div>'
            . '<div class="public-form-row"><label for="content">Comment</label><textarea id="content" name="content" required></textarea></div>'
            . '<div class="public-form-actions"><button type="submit">Post comment</button></div></form></section>';
    }

    /**
     * @param array<string, mixed> $page
     */
    private function commentsEnabledForPage(array $page): bool
    {
        if ($page['comments_enabled'] !== null) {
            return (bool) $page['comments_enabled'];
        }

        return (bool) $this->settings->get('comments.enabled', true);
    }

    private function moderationRequired(): bool
    {
        return (bool) $this->settings->get('comments.moderation_required', true);
    }

    /**
     * @return array<string, mixed>
     */
    private function requireComment(int $id): array
    {
        $comment = $this->comments->find($id);

        if ($comment === null) {
            throw new RuntimeException('Comment not found.');
        }

        return $comment;
    }

    private function syncCounts(int $pageId): void
    {
        $this->comments->updatePageCommentCount($pageId, $this->comments->approvedCount($pageId));
    }

    private function invalidate(): void
    {
        $this->cache->flush();
    }
}
