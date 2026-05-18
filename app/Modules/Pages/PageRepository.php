<?php

declare(strict_types=1);

namespace MidexCMS\Modules\Pages;

use MidexCMS\Core\Database;

final class PageRepository
{
    public function __construct(
        private readonly Database $database
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return $this->database->select(
            'SELECT id, parent_id, type, title, slug, path, content_mode, status, template, published_at, updated_at
             FROM pages
             WHERE deleted_at IS NULL
             ORDER BY path ASC'
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function parentOptions(?int $excludeId = null): array
    {
        $sql = 'SELECT id, title, path FROM pages WHERE deleted_at IS NULL';
        $params = [];

        if ($excludeId !== null) {
            $sql .= ' AND id != :exclude_id';
            $params['exclude_id'] = $excludeId;
        }

        $sql .= ' ORDER BY path ASC';

        return $this->database->select($sql, $params);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function children(int $parentId): array
    {
        return $this->database->select(
            'SELECT * FROM pages
             WHERE parent_id = :parent_id
               AND deleted_at IS NULL
             ORDER BY path ASC',
            ['parent_id' => $parentId]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function publishedChildren(int $parentId): array
    {
        return $this->database->select(
            'SELECT * FROM pages
             WHERE parent_id = :parent_id
               AND status = :status
               AND deleted_at IS NULL
             ORDER BY published_at DESC NULLS LAST, updated_at DESC, path ASC',
            ['parent_id' => $parentId, 'status' => 'published']
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->database->selectOne(
            'SELECT * FROM pages WHERE id = :id AND deleted_at IS NULL LIMIT 1',
            ['id' => $id]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findPublishedByPath(string $path): ?array
    {
        return $this->database->selectOne(
            'SELECT * FROM pages WHERE path = :path AND status = :status AND deleted_at IS NULL LIMIT 1',
            ['path' => $path, 'status' => 'published']
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function publishedForSitemap(): array
    {
        return $this->database->select(
            'SELECT id, path, updated_at
             FROM pages
             WHERE deleted_at IS NULL
               AND status = :status
             ORDER BY path ASC',
            ['status' => 'published']
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByPath(string $path): ?array
    {
        return $this->database->selectOne(
            'SELECT * FROM pages WHERE path = :path AND deleted_at IS NULL LIMIT 1',
            ['path' => $path]
        );
    }

    public function create(array $payload): int
    {
        $this->database->statement(
            'INSERT INTO pages (
                parent_id, type, title, slug, path, excerpt, content_mode, content_raw, content_html, status, template,
                seo_title, seo_description, seo_keywords, comments_enabled, published_at
            ) VALUES (
                :parent_id, :type, :title, :slug, :path, :excerpt, :content_mode, :content_raw, :content_html, :status, :template,
                :seo_title, :seo_description, :seo_keywords, :comments_enabled, :published_at
            )',
            $payload
        );

        $row = $this->database->selectOne('SELECT currval(pg_get_serial_sequence(\'pages\', \'id\')) AS id');

        return (int) ($row['id'] ?? 0);
    }

    public function update(int $id, array $payload): void
    {
        $payload['id'] = $id;
        $this->database->statement(
            'UPDATE pages SET
                parent_id = :parent_id,
                type = :type,
                title = :title,
                slug = :slug,
                path = :path,
                excerpt = :excerpt,
                content_mode = :content_mode,
                content_raw = :content_raw,
                content_html = :content_html,
                status = :status,
                template = :template,
                seo_title = :seo_title,
                seo_description = :seo_description,
                seo_keywords = :seo_keywords,
                comments_enabled = :comments_enabled,
                published_at = :published_at,
                updated_at = CURRENT_TIMESTAMP
             WHERE id = :id',
            $payload
        );
    }

    public function softDelete(int $id): void
    {
        $this->database->statement(
            'UPDATE pages SET deleted_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = :id',
            ['id' => $id]
        );
    }

    public function revision(int $pageId, array $payload): void
    {
        $this->database->statement(
            'INSERT INTO page_revisions (page_id, title, content_mode, content_raw, content_html, meta)
             VALUES (:page_id, :title, :content_mode, :content_raw, :content_html, CAST(:meta AS JSONB))',
            [
                'page_id' => $pageId,
                'title' => $payload['title'],
                'content_mode' => $payload['content_mode'],
                'content_raw' => $payload['content_raw'],
                'content_html' => $payload['content_html'],
                'meta' => json_encode($payload['meta'], JSON_THROW_ON_ERROR),
            ]
        );
    }

    public function slugExists(?int $parentId, string $slug, ?int $excludeId = null): bool
    {
        $sql = 'SELECT id FROM pages WHERE slug = :slug AND deleted_at IS NULL';
        $params = ['slug' => $slug];

        $sql .= ' AND ';

        if ($parentId === null) {
            $sql .= 'parent_id IS NULL';
        } else {
            $sql .= 'parent_id = :parent_id';
            $params['parent_id'] = $parentId;
        }

        if ($excludeId !== null) {
            $sql .= ' AND id != :exclude_id';
            $params['exclude_id'] = $excludeId;
        }

        return $this->database->selectOne($sql . ' LIMIT 1', $params) !== null;
    }

    public function pathExists(string $path, ?int $excludeId = null): bool
    {
        $sql = 'SELECT id FROM pages WHERE path = :path AND deleted_at IS NULL';
        $params = ['path' => $path];

        if ($excludeId !== null) {
            $sql .= ' AND id != :exclude_id';
            $params['exclude_id'] = $excludeId;
        }

        return $this->database->selectOne($sql . ' LIMIT 1', $params) !== null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function latestPublished(int $limit, ?int $excludeId = null): array
    {
        $limit = max(1, $limit);
        $sql = 'SELECT * FROM pages
            WHERE status = :status
              AND deleted_at IS NULL';
        $params = [
            'status' => 'published',
        ];

        if ($excludeId !== null) {
            $sql .= ' AND id != :exclude_id';
            $params['exclude_id'] = $excludeId;
        }

        $sql .= ' ORDER BY published_at DESC NULLS LAST, created_at DESC LIMIT ' . $limit;

        return $this->database->select($sql, $params);
    }
}
