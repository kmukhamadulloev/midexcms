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
        $moduleSlots = $manifest['module_slots'] ?? [];

        if (!is_array($templates)) {
            throw new RuntimeException(sprintf('Theme templates are invalid for theme "%s".', $key));
        }

        if (!is_array($moduleSlots)) {
            throw new RuntimeException(sprintf('Theme module slots are invalid for theme "%s".', $key));
        }

        $theme = [
            'key' => $manifest['key'] ?? $key,
            'name' => $manifest['name'] ?? $key,
            'version' => $manifest['version'] ?? '0.0.0',
            'author' => $manifest['author'] ?? 'Unknown',
            'path' => $themePath,
            'templates' => $templates,
            'module_slots' => $this->normalizeSlots($moduleSlots),
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

    /**
     * @param array<int|string, mixed> $slots
     * @return array<int, array{key: string, label: string, allowed_components: array<int, string>, multiple: bool, description: string}>
     */
    private function normalizeSlots(array $slots): array
    {
        $normalized = [];

        foreach ($slots as $slot) {
            if (!is_array($slot)) {
                continue;
            }

            $key = trim((string) ($slot['key'] ?? ''));

            if ($key === '') {
                continue;
            }

            $allowedComponents = array_values(array_filter(
                array_map(static fn (mixed $value): string => trim((string) $value), (array) ($slot['allowed_components'] ?? [])),
                static fn (string $value): bool => $value !== ''
            ));

            $normalized[] = [
                'key' => $key,
                'label' => trim((string) ($slot['label'] ?? $key)),
                'allowed_components' => $allowedComponents,
                'multiple' => (bool) ($slot['multiple'] ?? false),
                'description' => trim((string) ($slot['description'] ?? '')),
            ];
        }

        return $normalized;
    }
}
