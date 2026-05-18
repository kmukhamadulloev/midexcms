<?php

declare(strict_types=1);

namespace MidexCMS\Modules\SEO;

use MidexCMS\Core\Cache\CacheInterface;
use MidexCMS\Core\Support\Str;
use MidexCMS\Modules\Pages\PageRepository;

final class SeoService
{
    public function __construct(
        private readonly PageRepository $pages,
        private readonly CacheInterface $cache,
        private readonly string $appUrl
    ) {
    }

    /**
     * @param array<string, mixed> $page
     * @param array<string, mixed> $site
     * @return array<string, string>
     */
    public function metaForPage(array $page, array $site): array
    {
        $title = trim((string) ($page['seo_title'] ?? '')) !== '' ? (string) $page['seo_title'] : (string) $page['title'];
        $description = trim((string) ($page['seo_description'] ?? '')) !== '' ? (string) $page['seo_description'] : (string) ($page['excerpt'] ?? $site['site']['description'] ?? '');
        $keywords = (string) ($page['seo_keywords'] ?? '');
        $robots = ((bool) ($page['robots_index'] ?? true) ? 'index' : 'noindex') . ',' . ((bool) ($page['robots_follow'] ?? true) ? 'follow' : 'nofollow');
        $canonical = rtrim($this->appUrl, '/') . ((string) ($page['path'] ?? '/'));

        return [
            'title' => $title,
            'description' => $description,
            'keywords' => $keywords,
            'robots' => $robots,
            'canonical' => $canonical,
        ];
    }

    public function sitemapXml(): string
    {
        $cacheKey = 'seo:sitemap';
        $cached = $this->cache->get($cacheKey);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $pages = $this->pages->publishedForSitemap();
        $lines = ['<?xml version="1.0" encoding="UTF-8"?>', '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'];

        foreach ($pages as $page) {
            $lines[] = '  <url>';
            $lines[] = '    <loc>' . htmlspecialchars(rtrim($this->appUrl, '/') . (string) $page['path'], ENT_XML1, 'UTF-8') . '</loc>';

            if (is_string($page['updated_at'] ?? null) && $page['updated_at'] !== '') {
                $lines[] = '    <lastmod>' . date(DATE_ATOM, strtotime((string) $page['updated_at'])) . '</lastmod>';
            }

            $lines[] = '  </url>';
        }

        $lines[] = '</urlset>';
        $xml = implode("\n", $lines);
        $this->cache->put($cacheKey, $xml, 3600);

        return $xml;
    }

    public function robotsTxt(): string
    {
        $cacheKey = 'seo:robots';
        $cached = $this->cache->get($cacheKey);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $body = "User-agent: *\nAllow: /\nDisallow: /admin\n\nSitemap: " . rtrim($this->appUrl, '/') . "/sitemap.xml\n";
        $this->cache->put($cacheKey, $body, 3600);

        return $body;
    }
}
