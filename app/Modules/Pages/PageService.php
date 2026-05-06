<?php

declare(strict_types=1);

namespace PlainCMS\Modules\Pages;

use PlainCMS\Core\Cache\CacheInterface;
use PlainCMS\Core\Support\Str;
use PlainCMS\Core\Support\Url;

final class PageService
{
    public function __construct(
        private readonly PageRepository $pages,
        private readonly ContentRenderer $renderer,
        private readonly PagePreviewSigner $previewSigner,
        private readonly CacheInterface $cache
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listPages(): array
    {
        return $this->pages->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function parentOptions(?int $excludeId = null): array
    {
        return $this->pages->parentOptions($excludeId);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findPage(int $id): ?array
    {
        return $this->pages->find($id);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function publishedChildren(int $pageId): array
    {
        return $this->pages->publishedChildren($pageId);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function latestPublished(int $limit, ?int $excludeId = null): array
    {
        return $this->pages->latestPublished($limit, $excludeId);
    }

    public function create(array $input): int
    {
        $normalized = $this->normalizePayload($input, null);
        $id = $this->pages->create($normalized);
        $this->pages->revision($id, [
            'title' => $normalized['title'],
            'content_mode' => $normalized['content_mode'],
            'content_raw' => $normalized['content_raw'],
            'content_html' => $normalized['content_html'],
            'meta' => $this->revisionMeta($normalized),
        ]);
        $this->invalidate();

        return $id;
    }

    public function update(int $id, array $input): void
    {
        $existing = $this->requirePage($id);
        $normalized = $this->normalizePayload($input, $id, $existing);
        $this->pages->update($id, $normalized);
        $this->syncDescendantPaths($id, $normalized['path']);
        $this->pages->revision($id, [
            'title' => $normalized['title'],
            'content_mode' => $normalized['content_mode'],
            'content_raw' => $normalized['content_raw'],
            'content_html' => $normalized['content_html'],
            'meta' => $this->revisionMeta($normalized),
        ]);
        $this->invalidate();
    }

    public function publish(int $id): void
    {
        $page = $this->requirePage($id);
        $page['status'] = 'published';
        $page['published_at'] = $page['published_at'] ?? date('Y-m-d H:i:s');
        $this->pages->update($id, $this->fillUpdatePayload($page));
        $this->invalidate();
    }

    public function archive(int $id): void
    {
        $page = $this->requirePage($id);
        $page['status'] = 'archived';
        $this->pages->update($id, $this->fillUpdatePayload($page));
        $this->invalidate();
    }

    public function delete(int $id): void
    {
        $this->pages->softDelete($id);
        $this->invalidate();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function publishedByPath(string $path): ?array
    {
        return $this->pages->findPublishedByPath($path);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function previewByToken(string $token): ?array
    {
        $pageId = $this->previewSigner->verify($token);

        if ($pageId === null) {
            return null;
        }

        return $this->pages->find($pageId);
    }

    public function previewToken(int $pageId): string
    {
        return $this->previewSigner->sign($pageId);
    }

    private function normalizePayload(array $input, ?int $pageId, ?array $existing = null): array
    {
        $parentId = isset($input['parent_id']) && $input['parent_id'] !== '' ? (int) $input['parent_id'] : null;
        $title = trim((string) ($input['title'] ?? ''));
        $slug = trim((string) ($input['slug'] ?? ''));
        $slug = $slug !== '' ? Str::slug($slug) : Str::slug($title);
        $path = trim((string) ($input['path'] ?? ''));
        $path = $parentId !== null
            ? $this->buildPath($parentId, $slug)
            : ($path !== '' ? $this->normalizePath($path) : $this->buildPath(null, $slug));
        $contentMode = (string) ($input['content_mode'] ?? 'markdown');
        $contentRaw = (string) ($input['content_raw'] ?? '');
        $contentHtml = $this->renderer->render($contentMode, $contentRaw);
        $status = (string) ($input['status'] ?? 'draft');
        $publishedAt = $status === 'published'
            ? (($input['published_at'] ?? null) ?: date('Y-m-d H:i:s'))
            : null;
        $type = $existing !== null ? (string) ($existing['type'] ?? 'page') : 'page';

        if ($pageId !== null) {
            $this->assertNoCircularParent($pageId, $parentId);
        }

        $normalized = [
            'parent_id' => $parentId,
            'type' => $type,
            'title' => $title,
            'slug' => $slug,
            'path' => $path,
            'excerpt' => $this->nullable($input['excerpt'] ?? null),
            'content_mode' => $contentMode,
            'content_raw' => $contentRaw,
            'content_html' => $contentHtml,
            'status' => $status,
            'template' => $this->nullable($input['template'] ?? null),
            'seo_title' => $this->nullable($input['seo_title'] ?? null),
            'seo_description' => $this->nullable($input['seo_description'] ?? null),
            'seo_keywords' => $this->nullable($input['seo_keywords'] ?? null),
            'comments_enabled' => $this->nullableBoolean($input['comments_enabled'] ?? null),
            'published_at' => $publishedAt,
        ];

        if ($this->pages->slugExists($parentId, $slug, $pageId)) {
            throw new \RuntimeException('Slug must be unique within the selected parent page.');
        }

        if ($this->pages->pathExists($path, $pageId)) {
            throw new \RuntimeException('Path must be globally unique.');
        }

        return $normalized;
    }

    private function buildPath(?int $parentId, string $slug): string
    {
        if ($parentId === null) {
            return Url::join($slug);
        }

        $parent = $this->requirePage($parentId);

        return Url::join((string) $parent['path'], $slug);
    }

    private function normalizePath(string $path): string
    {
        $path = '/' . trim($path, '/');

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    private function assertNoCircularParent(int $pageId, ?int $parentId): void
    {
        $cursor = $parentId;

        while ($cursor !== null) {
            if ($cursor === $pageId) {
                throw new \RuntimeException('A page cannot be nested under itself or its own descendants.');
            }

            $parent = $this->pages->find($cursor);

            if ($parent === null) {
                throw new \RuntimeException('Selected parent page was not found.');
            }

            $cursor = $parent['parent_id'] !== null ? (int) $parent['parent_id'] : null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function fillUpdatePayload(array $page): array
    {
        return [
            'parent_id' => $page['parent_id'] !== null ? (int) $page['parent_id'] : null,
            'type' => (string) $page['type'],
            'title' => (string) $page['title'],
            'slug' => (string) $page['slug'],
            'path' => (string) $page['path'],
            'excerpt' => $page['excerpt'],
            'content_mode' => (string) $page['content_mode'],
            'content_raw' => (string) $page['content_raw'],
            'content_html' => (string) $page['content_html'],
            'status' => (string) $page['status'],
            'template' => $page['template'],
            'seo_title' => $page['seo_title'],
            'seo_description' => $page['seo_description'],
            'seo_keywords' => $page['seo_keywords'],
            'comments_enabled' => $page['comments_enabled'],
            'published_at' => $page['published_at'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function revisionMeta(array $payload): array
    {
        return [
            'path' => $payload['path'],
            'status' => $payload['status'],
            'template' => $payload['template'],
            'seo_title' => $payload['seo_title'],
            'seo_description' => $payload['seo_description'],
            'seo_keywords' => $payload['seo_keywords'],
            'comments_enabled' => $payload['comments_enabled'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function requirePage(int $id): array
    {
        $page = $this->pages->find($id);

        if ($page === null) {
            throw new \RuntimeException('Page not found.');
        }

        return $page;
    }

    private function syncDescendantPaths(int $pageId, string $pagePath): void
    {
        foreach ($this->pages->children($pageId) as $child) {
            $child['path'] = Url::join($pagePath, (string) $child['slug']);
            $this->pages->update((int) $child['id'], $this->fillUpdatePayload($child));
            $this->syncDescendantPaths((int) $child['id'], (string) $child['path']);
        }
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function nullableBoolean(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value === true || $value === '1' || $value === 1;
    }

    private function invalidate(): void
    {
        $this->cache->flush();
    }
}
