<?php

declare(strict_types=1);

namespace MidexCMS\Core;

use MidexCMS\Core\Theme\ThemeLoader;
use RuntimeException;

final class TemplateEngine
{
    /**
     * @param array<int, string> $safeHtmlSlots
     */
    public function __construct(
        private readonly ThemeLoader $themes,
        private readonly array $safeHtmlSlots = ['page.content', 'post.content', 'archive.content', 'archive.posts', 'menu.main', 'comments', 'likes', 'flash.message', 'content']
    ) {
    }

    /**
     * @param array<string, mixed> $theme
     * @param array<string, mixed> $data
     */
    public function renderTemplate(array $theme, string $templateName, array $data): string
    {
        $path = $this->themes->resolveTemplate($theme, $templateName);

        return $this->renderFile($theme, $path, $data);
    }

    /**
     * @param array<string, mixed> $theme
     * @param array<string, mixed> $data
     */
    public function renderLayout(array $theme, string $content, array $data): string
    {
        $data['content'] = $content;

        return $this->renderTemplate($theme, 'layout', $data);
    }

    /**
     * @param array<string, mixed> $theme
     * @param array<string, mixed> $data
     */
    private function renderFile(array $theme, string $path, array $data): string
    {
        $template = (string) file_get_contents($path);

        if (str_contains($template, '<?')) {
            throw new RuntimeException('PHP tags are not allowed in theme templates.');
        }

        $template = preg_replace_callback('/\{\{\s*include:([^}]+)\s*\}\}/', function (array $matches) use ($theme, $data): string {
            $relativePath = trim($matches[1]);
            $includePath = $this->resolveIncludePath((string) $theme['path'], $relativePath);

            return $this->renderFile($theme, $includePath, $data);
        }, $template) ?? $template;

        $template = preg_replace_callback('/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/', function (array $matches) use ($data): string {
            $key = $matches[1];
            $value = $this->resolveValue($data, $key);

            if ($value === null) {
                return '';
            }

            if (in_array($key, $this->safeHtmlSlots, true)) {
                return (string) $value;
            }

            return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        }, $template) ?? $template;

        return $template;
    }

    private function resolveIncludePath(string $themePath, string $relativePath): string
    {
        if (str_contains($relativePath, '..')) {
            throw new RuntimeException('Path traversal is not allowed in theme includes.');
        }

        $path = rtrim($themePath, '/') . '/' . ltrim($relativePath, '/');
        $realBase = realpath($themePath);
        $realPath = realpath($path);

        if ($realBase === false || $realPath === false || $realPath !== $realBase && !str_starts_with($realPath, $realBase . '/')) {
            throw new RuntimeException('Theme include resolves outside the active theme.');
        }

        return $realPath;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function resolveValue(array $data, string $key): mixed
    {
        $segments = explode('.', $key);
        $value = $data;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;
    }
}
