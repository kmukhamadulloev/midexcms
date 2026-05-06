<?php

declare(strict_types=1);

namespace PlainCMS\Modules\Comments;

use PlainCMS\Core\Database;

final class CommentRepository
{
    public function __construct(
        private readonly Database $database
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function adminList(): array
    {
        return $this->database->select(
            'SELECT comments.id, comments.page_id, comments.author_name, comments.author_email, comments.author_url, comments.content, comments.status, comments.created_at,
                    pages.title AS page_title, pages.path AS page_path
             FROM comments
             INNER JOIN pages ON pages.id = comments.page_id
             ORDER BY comments.id DESC'
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function approvedForPage(int $pageId): array
    {
        return $this->database->select(
            'SELECT id, page_id, parent_id, author_name, author_url, content, created_at
             FROM comments
             WHERE page_id = :page_id AND status = :status
             ORDER BY id ASC',
            ['page_id' => $pageId, 'status' => 'approved']
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->database->selectOne('SELECT * FROM comments WHERE id = :id LIMIT 1', ['id' => $id]);
    }

    public function create(array $payload): int
    {
        $this->database->statement(
            'INSERT INTO comments (page_id, parent_id, author_name, author_email, author_url, content, status, ip_hash, user_agent_hash)
             VALUES (:page_id, :parent_id, :author_name, :author_email, :author_url, :content, :status, :ip_hash, :user_agent_hash)',
            $payload
        );

        $row = $this->database->selectOne('SELECT currval(pg_get_serial_sequence(\'comments\', \'id\')) AS id');

        return (int) ($row['id'] ?? 0);
    }

    public function setStatus(int $id, string $status): void
    {
        $this->database->statement(
            'UPDATE comments SET status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id',
            ['id' => $id, 'status' => $status]
        );
    }

    public function delete(int $id): void
    {
        $this->database->statement('DELETE FROM comments WHERE id = :id', ['id' => $id]);
    }

    public function approvedCount(int $pageId): int
    {
        $row = $this->database->selectOne(
            'SELECT COUNT(*) AS aggregate FROM comments WHERE page_id = :page_id AND status = :status',
            ['page_id' => $pageId, 'status' => 'approved']
        );

        return (int) ($row['aggregate'] ?? 0);
    }

    public function updatePageCommentCount(int $pageId, int $count): void
    {
        $this->database->statement(
            'UPDATE pages SET comments_count = :count, updated_at = CURRENT_TIMESTAMP WHERE id = :id',
            ['id' => $pageId, 'count' => $count]
        );
    }
}
