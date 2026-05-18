<?php

declare(strict_types=1);

namespace MidexCMS\Core\Theme;

use MidexCMS\Core\Cache\CacheInterface;
use MidexCMS\Core\Csrf;
use MidexCMS\Core\Flash;
use MidexCMS\Core\Request;
use MidexCMS\Core\SettingsService;
use MidexCMS\Core\TemplateEngine;
use MidexCMS\Modules\Comments\CommentService;
use MidexCMS\Modules\Likes\LikeService;
use MidexCMS\Modules\Menus\MenuService;
use MidexCMS\Modules\Pages\ContentRenderer;
use MidexCMS\Modules\Pages\ShortcodeRenderer;
use MidexCMS\Modules\SEO\SeoService;

final class ThemeRenderer
{
    public function __construct(
        private readonly ThemeLoader $themes,
        private readonly TemplateEngine $templates,
        private readonly SettingsService $settings,
        private readonly CacheInterface $cache,
        private readonly MenuService $menus,
        private readonly ContentRenderer $content,
        private readonly ShortcodeRenderer $shortcodes,
        private readonly CommentService $comments,
        private readonly LikeService $likes,
        private readonly SeoService $seo,
        private readonly Csrf $csrf,
        private readonly Flash $flash
    ) {
    }

    /**
     * @param array<string, mixed> $page
     */
    public function renderPage(array $page, Request $request, bool $preview = false): string
    {
        $site = $this->settings->siteContext()->toArray();
        $themeKey = (string) $site['theme']['active'];
        $cacheKey = 'public_page:' . sha1($themeKey . '|' . (string) $page['path'] . '|' . (string) $page['updated_at']);
        $flash = $this->flash->pull();
        $interactive = $preview || $flash !== null || $this->isInteractivePage($page, $site);

        if (!$interactive) {
            $cached = $this->cache->get($cacheKey);

            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
        }

        $theme = $this->themes->load($themeKey);
        $data = $this->data($page, $site, $themeKey, $request, $flash, $preview);
        $content = $this->templates->renderTemplate($theme, $this->templateName($page), $data);
        $html = $this->templates->renderLayout($theme, $content, $data);

        if (!$interactive) {
            $this->cache->put($cacheKey, $html, 3600);
        }

        return $html;
    }

    /**
     * @param array<string, mixed> $archivePage
     * @param array<int, array<string, mixed>> $posts
     */
    public function renderArchive(array $archivePage, array $posts, Request $request): string
    {
        $site = $this->settings->siteContext()->toArray();
        $themeKey = (string) $site['theme']['active'];
        $flash = $this->flash->pull();
        $cacheFingerprint = (string) ($archivePage['updated_at'] ?? '') . '|' . count($posts);

        if ($posts !== []) {
            $latest = $posts[0];
            $cacheFingerprint .= '|' . (string) ($latest['updated_at'] ?? '') . '|' . (string) ($latest['id'] ?? '');
        }

        $cacheKey = 'public_archive:' . sha1($themeKey . '|' . $cacheFingerprint);
        $interactive = $flash !== null || $this->containsInteractiveShortcodes((string) ($archivePage['content_raw'] ?? ''));

        if (!$interactive) {
            $cached = $this->cache->get($cacheKey);

            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
        }

        $theme = $this->themes->load($themeKey);
        $data = $this->archiveData($archivePage, $posts, $site, $themeKey, $flash);
        $content = $this->templates->renderTemplate($theme, 'archive', $data);
        $html = $this->templates->renderLayout($theme, $content, $data);

        if (!$interactive) {
            $this->cache->put($cacheKey, $html, 3600);
        }

        return $html;
    }

    /**
     * @param array<string, mixed> $page
     */
    private function templateName(array $page): string
    {
        $custom = trim((string) ($page['template'] ?? ''));

        if ($custom !== '') {
            return $custom;
        }

        return match ((string) ($page['type'] ?? 'page')) {
            'contact' => 'contact',
            default => ((string) ($page['path'] ?? '') === '/' ? 'home' : 'page'),
        };
    }

    /**
     * @param array<string, mixed> $page
     * @param array<string, mixed> $site
     * @return array<string, mixed>
     */
    private function data(array $page, array $site, string $themeKey, Request $request, ?array $flash, bool $preview): array
    {
        $csrfToken = $this->csrf->token();
        $renderedContent = $this->content->renderWithTransform(
            (string) $page['content_mode'],
            (string) ($page['content_raw'] ?? ''),
            fn (string $html): string => $this->shortcodes->render($html, [
                'page' => $page,
                'csrf_input' => $this->csrf->inputName(),
                'csrf_token' => $csrfToken,
            ])
        );
        $commentsHtml = $preview
            ? ''
            : $this->comments->renderForPage(
                $page,
                '/comments/submit/' . (int) $page['id'],
                $this->csrf->inputName(),
                $csrfToken
            );
        $likesHtml = !$preview && (string) ($page['type'] ?? '') === 'post'
            ? $this->likes->renderForPage(
                $page,
                '/likes/toggle/' . (int) $page['id'],
                $this->csrf->inputName(),
                $csrfToken,
                $this->likes->visitorHash(
                    (string) $request->server('REMOTE_ADDR', 'unknown'),
                    (string) $request->server('HTTP_USER_AGENT', 'unknown')
                )
            )
            : '';
        $pageData = [
            'id' => (int) $page['id'],
            'title' => (string) $page['title'],
            'excerpt' => (string) ($page['excerpt'] ?? ''),
            'content' => $renderedContent,
            'published_at' => (string) ($page['published_at'] ?? ''),
            'path' => (string) $page['path'],
            'status' => (string) $page['status'],
            'template' => (string) ($page['template'] ?? ''),
            'type' => (string) ($page['type'] ?? 'page'),
            'preview' => $preview ? 'true' : '',
        ];

        return [
            'site' => [
                'title' => (string) $site['site']['title'],
                'description' => (string) $site['site']['description'],
                'url' => '/',
                'theme_asset_base' => '/theme-assets/' . rawurlencode($themeKey),
            ],
            'page' => $pageData,
            'post' => $pageData,
            'menu' => ['main' => $this->menus->renderMenu('main')],
            'comments' => $commentsHtml,
            'likes' => $likesHtml,
            'meta' => $this->seo->metaForPage($page, $site),
            'flash' => ['message' => $this->flashHtml($flash)],
        ];
    }

    /**
     * @param array<string, mixed> $archivePage
     * @param array<int, array<string, mixed>> $posts
     * @param array<string, mixed> $site
     * @return array<string, mixed>
     */
    private function archiveData(array $archivePage, array $posts, array $site, string $themeKey, ?array $flash): array
    {
        $items = '';

        foreach ($posts as $post) {
            $title = htmlspecialchars((string) $post['title'], ENT_QUOTES, 'UTF-8');
            $path = htmlspecialchars((string) $post['path'], ENT_QUOTES, 'UTF-8');
            $excerpt = trim((string) ($post['excerpt'] ?? ''));
            $publishedAt = trim((string) ($post['published_at'] ?? ''));
            $meta = $publishedAt !== '' ? '<p class="post-meta">' . htmlspecialchars($publishedAt, ENT_QUOTES, 'UTF-8') . '</p>' : '';
            $summary = $excerpt !== '' ? '<p>' . htmlspecialchars($excerpt, ENT_QUOTES, 'UTF-8') . '</p>' : '';
            $items .= '<article class="post-card"><h2><a href="' . $path . '">' . $title . '</a></h2>' . $meta . $summary . '</article>';
        }

        if ($items === '') {
            $items = '<p>No published posts yet.</p>';
        }

        return [
            'site' => [
                'title' => (string) $site['site']['title'],
                'description' => (string) $site['site']['description'],
                'url' => '/',
                'theme_asset_base' => '/theme-assets/' . rawurlencode($themeKey),
            ],
            'page' => [
                'title' => (string) $archivePage['title'],
                'excerpt' => (string) ($archivePage['excerpt'] ?? ''),
                'content' => $this->content->renderWithTransform(
                    (string) $archivePage['content_mode'],
                    (string) ($archivePage['content_raw'] ?? ''),
                    fn (string $html): string => $this->shortcodes->render($html, [
                        'page' => $archivePage,
                        'csrf_input' => $this->csrf->inputName(),
                        'csrf_token' => $this->csrf->token(),
                    ])
                ),
                'path' => (string) $archivePage['path'],
                'status' => (string) $archivePage['status'],
                'type' => 'archive',
            ],
            'archive' => [
                'title' => (string) $archivePage['title'],
                'excerpt' => (string) ($archivePage['excerpt'] ?? ''),
                'content' => $this->content->renderWithTransform(
                    (string) $archivePage['content_mode'],
                    (string) ($archivePage['content_raw'] ?? ''),
                    fn (string $html): string => $this->shortcodes->render($html, [
                        'page' => $archivePage,
                        'csrf_input' => $this->csrf->inputName(),
                        'csrf_token' => $this->csrf->token(),
                    ])
                ),
                'path' => (string) $archivePage['path'],
                'posts' => $items,
            ],
            'menu' => ['main' => $this->menus->renderMenu('main')],
            'comments' => '',
            'likes' => '',
            'meta' => $this->seo->metaForPage($archivePage, $site),
            'flash' => ['message' => $this->flashHtml($flash)],
        ];
    }

    /**
     * @param array<string, mixed> $page
     * @param array<string, mixed> $site
     */
    private function isInteractivePage(array $page, array $site): bool
    {
        if ($this->containsInteractiveShortcodes((string) ($page['content_raw'] ?? ''))) {
            return true;
        }

        if ($page['comments_enabled'] !== null) {
            return (bool) $page['comments_enabled'];
        }

        return (bool) ($site['comments']['enabled'] ?? false);
    }

    private function containsInteractiveShortcodes(string $content): bool
    {
        return preg_match('/\[(contact_form|latest_pages|latest_posts|child_pages|gallery|button)\b/i', $content) === 1;
    }

    private function flashHtml(?array $flash): string
    {
        if ($flash === null) {
            return '';
        }

        $class = ($flash['type'] ?? 'success') === 'error' ? 'public-flash flash-error' : 'public-flash flash-success';

        return '<div class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars((string) ($flash['message'] ?? ''), ENT_QUOTES, 'UTF-8') . '</div>';
    }
}
