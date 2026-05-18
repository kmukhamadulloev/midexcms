<?php

declare(strict_types=1);

namespace MidexCMS\Modules\Likes;

use MidexCMS\Core\Database;

final class LikeRepository
{
    public function __construct(
        private readonly Database $database
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $pageId, string $visitorHash): ?array
    {
        return $this->database->selectOne(
            'SELECT id FROM likes WHERE page_id = :page_id AND visitor_hash = :visitor_hash LIMIT 1',
            ['page_id' => $pageId, 'visitor_hash' => $visitorHash]
        );
    }

    public function create(int $pageId, string $visitorHash): void
    {
        $this->database->statement(
            'INSERT INTO likes (page_id, visitor_hash) VALUES (:page_id, :visitor_hash)',
            ['page_id' => $pageId, 'visitor_hash' => $visitorHash]
        );
    }

    public function delete(int $pageId, string $visitorHash): void
    {
        $this->database->statement(
            'DELETE FROM likes WHERE page_id = :page_id AND visitor_hash = :visitor_hash',
            ['page_id' => $pageId, 'visitor_hash' => $visitorHash]
        );
    }

    public function count(int $pageId): int
    {
        $row = $this->database->selectOne(
            'SELECT COUNT(*) AS aggregate FROM likes WHERE page_id = :page_id',
            ['page_id' => $pageId]
        );

        return (int) ($row['aggregate'] ?? 0);
    }

    public function updatePageCount(int $pageId, int $count): void
    {
        $this->database->statement(
            'UPDATE pages SET likes_count = :count, updated_at = CURRENT_TIMESTAMP WHERE id = :id',
            ['id' => $pageId, 'count' => $count]
        );
    }
}
