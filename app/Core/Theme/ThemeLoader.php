<?php

declare(strict_types=1);

namespace MidexCMS\Core\Theme;

use MidexCMS\Core\Cache\CacheInterface;
use RuntimeException;

final class ThemeLoader
{
    public function __construct(
        private readonly string $themesPath,
        private readonly CacheInterface $cache
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function load(string $key): array
    {
        $cacheKey = 'theme:manifest:' . $key;
        $cached = $this->cache->get($cacheKey);

        if (is_array($cached)) {
            return $cached;
        }

        $themePath = $this->themePath($key);
        $manifestPath = $themePath . '/theme.json';

        if (!is_file($manifestPath)) {
            throw new RuntimeException(sprintf('Theme manifest not found for theme "%s".', $key));
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true);

        if (!is_array($manifest)) {
            throw new RuntimeException(sprintf('Theme manifest is invalid for theme "%s".', $key));
        }

        $templates = $manifest['templates'] ?? [];

        if (!is_array($templates)) {
            throw new RuntimeException(sprintf('Theme templates are invalid for theme "%s".', $key));
        }

        $theme = [
            'key' => $manifest['key'] ?? $key,
            'name' => $manifest['name'] ?? $key,
            'version' => $manifest['version'] ?? '0.0.0',
            'author' => $manifest['author'] ?? 'Unknown',
            'path' => $themePath,
            'templates' => $templates,
        ];

        $this->cache->put($cacheKey, $theme, 3600);

        return $theme;
    }

    public function themePath(string $key): string
    {
        $safeKey = basename($key);
        $path = rtrim($this->themesPath, '/') . '/' . $safeKey;

        if (!is_dir($path)) {
            throw new RuntimeException(sprintf('Theme directory not found for theme "%s".', $safeKey));
        }

        return $path;
    }

    public function resolveTemplate(array $theme, string $name): string
    {
        $templates = $theme['templates'] ?? [];
        $relative = $templates[$name] ?? null;

        if (!is_string($relative) || $relative === '') {
            throw new RuntimeException(sprintf('Template "%s" is not defined for theme "%s".', $name, (string) ($theme['key'] ?? 'unknown')));
        }

        return $this->safePath((string) $theme['path'], $relative);
    }

    public function resolveAsset(string $themeKey, string $asset): string
    {
        return $this->safePath($this->themePath($themeKey), 'assets/' . ltrim($asset, '/'));
    }

    private function safePath(string $basePath, string $relativePath): string
    {
        if (str_contains($relativePath, '..')) {
            throw new RuntimeException('Path traversal is not allowed in theme paths.');
        }

        $fullPath = rtrim($basePath, '/') . '/' . ltrim($relativePath, '/');
        $realBase = realpath($basePath);
        $realPath = realpath($fullPath);

        if ($realBase === false || $realPath === false || $realPath !== $realBase && !str_starts_with($realPath, $realBase . '/')) {
            throw new RuntimeException('Theme path resolves outside the active theme.');
        }

        return $realPath;
    }
}
