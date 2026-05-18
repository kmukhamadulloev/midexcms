<?php

declare(strict_types=1);

namespace MidexCMS\Modules\Pages;

use MidexCMS\Modules\Forms\FormService;
use MidexCMS\Modules\Media\MediaService;

final class ShortcodeRenderer
{
    public function __construct(
        private readonly FormService $forms,
        private readonly PageService $pages,
        private readonly MediaService $media
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function render(string $html, array $context): string
    {
        $patterns = ['contact_form', 'button', 'latest_pages', 'latest_posts', 'child_pages', 'gallery'];

        foreach ($patterns as $name) {
            $html = preg_replace_callback(
                '#<p>\s*\[' . preg_quote($name, '#') . '([^\]]*)\]\s*</p>#i',
                fn (array $matches): string => $this->renderShortcode($name, $this->parseAttributes($matches[1]), $context),
                $html
            ) ?? $html;

            $html = preg_replace_callback(
                '#\[' . preg_quote($name, '#') . '([^\]]*)\]#i',
                fn (array $matches): string => $this->renderShortcode($name, $this->parseAttributes($matches[1]), $context),
                $html
            ) ?? $html;
        }

        return $html;
    }

    /**
     * @param array<string, string> $attributes
     * @param array<string, mixed> $context
     */
    private function renderShortcode(string $name, array $attributes, array $context): string
    {
        return match ($name) {
            'contact_form' => $this->renderContactForm($attributes, $context),
            'button' => $this->renderButton($attributes),
            'latest_pages', 'latest_posts' => $this->renderLatestPages($attributes, $context),
            'child_pages' => $this->renderChildPages($attributes, $context),
            'gallery' => $this->renderGallery($attributes),
            default => '',
        };
    }

    /**
     * @param array<string, string> $attributes
     * @param array<string, mixed> $context
     */
    private function renderContactForm(array $attributes, array $context): string
    {
        $key = trim((string) ($attributes['id'] ?? $attributes['key'] ?? ''));

        if ($key === '') {
            return '';
        }

        $form = $this->forms->formByKey($key);

        if ($form === null) {
            return '';
        }

        return $this->forms->renderPublicForm(
            $form,
            isset($context['page']['id']) ? (int) $context['page']['id'] : null,
            '/forms/submit/' . rawurlencode($key),
            (string) $context['csrf_input'],
            (string) $context['csrf_token'],
        );
    }

    /**
     * @param array<string, string> $attributes
     */
    private function renderButton(array $attributes): string
    {
        $text = trim((string) ($attributes['text'] ?? 'Learn more'));
        $url = trim((string) ($attributes['url'] ?? '/'));

        return '<a class="shortcode-button" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</a>';
    }

    /**
     * @param array<string, string> $attributes
     */
    private function renderLatestPages(array $attributes, array $context): string
    {
        $count = isset($attributes['count']) && ctype_digit($attributes['count']) ? max(1, min(10, (int) $attributes['count'])) : 5;
        $excludeId = isset($context['page']['id']) ? (int) $context['page']['id'] : null;
        $pages = $this->pages->latestPublished($count, $excludeId);

        return $this->renderPageCards($pages, 'No published pages yet.', 'latest-pages');
    }

    /**
     * @param array<string, string> $attributes
     * @param array<string, mixed> $context
     */
    private function renderChildPages(array $attributes, array $context): string
    {
        $parentId = $this->resolveParentId($attributes, $context);

        if ($parentId === null) {
            return '<section class="post-archive"><p>No child pages yet.</p></section>';
        }

        return $this->renderPageCards($this->pages->publishedChildren($parentId), 'No child pages yet.', 'child-pages');
    }

    /**
     * @param array<string, string> $attributes
     */
    private function renderGallery(array $attributes): string
    {
        $rawIds = trim((string) ($attributes['ids'] ?? $attributes['id'] ?? ''));

        if ($rawIds === '') {
            return '';
        }

        $items = '';
        $ids = array_filter(array_map('trim', explode(',', $rawIds)), static fn (string $id): bool => ctype_digit($id));

        foreach ($ids as $id) {
            $media = $this->media->find((int) $id);

            if ($media === null) {
                continue;
            }

            $alt = htmlspecialchars((string) ($media['alt'] ?? ''), ENT_QUOTES, 'UTF-8');
            $items .= '<figure class="gallery-item"><img src="' . htmlspecialchars((string) $media['url'], ENT_QUOTES, 'UTF-8') . '" alt="' . $alt . '"></figure>';
        }

        return $items === '' ? '' : '<section class="gallery-grid">' . $items . '</section>';
    }

    /**
     * @return array<string, string>
     */
    private function parseAttributes(string $raw): array
    {
        preg_match_all('/([a-zA-Z_][a-zA-Z0-9_]*)="([^"]*)"/', $raw, $matches, PREG_SET_ORDER);
        $attributes = [];

        foreach ($matches as $match) {
            $attributes[$match[1]] = $match[2];
        }

        return $attributes;
    }

    /**
     * @param array<string, string> $attributes
     * @param array<string, mixed> $context
     */
    private function resolveParentId(array $attributes, array $context): ?int
    {
        if (isset($attributes['id']) && ctype_digit($attributes['id'])) {
            return (int) $attributes['id'];
        }

        if (isset($attributes['path'])) {
            $page = $this->pages->publishedByPath('/' . trim($attributes['path'], '/'));

            if ($page !== null) {
                return (int) $page['id'];
            }
        }

        if (isset($context['page']['id']) && ctype_digit((string) $context['page']['id'])) {
            return (int) $context['page']['id'];
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $pages
     */
    private function renderPageCards(array $pages, string $emptyMessage, string $className): string
    {
        if ($pages === []) {
            return '<section class="post-archive ' . htmlspecialchars($className, ENT_QUOTES, 'UTF-8') . '"><p>' . htmlspecialchars($emptyMessage, ENT_QUOTES, 'UTF-8') . '</p></section>';
        }

        $items = '';

        foreach ($pages as $page) {
            $title = htmlspecialchars((string) $page['title'], ENT_QUOTES, 'UTF-8');
            $path = htmlspecialchars((string) $page['path'], ENT_QUOTES, 'UTF-8');
            $excerpt = trim((string) ($page['excerpt'] ?? ''));
            $publishedAt = trim((string) ($page['published_at'] ?? ''));
            $meta = $publishedAt !== '' ? '<p class="post-meta">' . htmlspecialchars($publishedAt, ENT_QUOTES, 'UTF-8') . '</p>' : '';
            $summary = $excerpt !== '' ? '<p>' . htmlspecialchars($excerpt, ENT_QUOTES, 'UTF-8') . '</p>' : '';
            $items .= '<article class="post-card"><h2><a href="' . $path . '">' . $title . '</a></h2>' . $meta . $summary . '</article>';
        }

        return '<section class="post-archive ' . htmlspecialchars($className, ENT_QUOTES, 'UTF-8') . '">' . $items . '</section>';
    }
}
