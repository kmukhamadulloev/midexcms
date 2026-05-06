<?php

declare(strict_types=1);

namespace PlainCMS\Core\Admin;

use RuntimeException;

final class AdminTemplateRenderer
{
    /**
     * @param array<int, string> $safeHtmlSlots
     */
    public function __construct(
        private readonly string $basePath,
        private readonly array $safeHtmlSlots = ['content', 'navigation_html', 'flash_html', 'stats_html', 'fields_html', 'actions_html', 'body']
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function render(string $template, array $data): string
    {
        $path = $this->resolveTemplatePath($template);

        return $this->renderFile($path, $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function renderFile(string $path, array $data): string
    {
        $template = (string) file_get_contents($path);

        if (str_contains($template, '<?')) {
            throw new RuntimeException('PHP tags are not allowed in admin templates.');
        }

        $template = preg_replace_callback('/\{\{\s*include:([^}]+)\s*\}\}/', function (array $matches) use ($data): string {
            return $this->renderFile($this->resolveTemplatePath(trim($matches[1])), $data);
        }, $template) ?? $template;

        $template = preg_replace_callback('/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/', function (array $matches) use ($data): string {
            $key = $matches[1];
            $value = $this->resolveValue($data, $key);

            if ($value === null) {
                return '';
            }

            if ($this->isSafeHtmlSlot($key)) {
                return (string) $value;
            }

            return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        }, $template) ?? $template;

        return $template;
    }

    private function resolveTemplatePath(string $relativePath): string
    {
        if (str_contains($relativePath, '..')) {
            throw new RuntimeException('Path traversal is not allowed in admin templates.');
        }

        $path = rtrim($this->basePath, '/') . '/' . ltrim($relativePath, '/');
        $realBase = realpath($this->basePath);
        $realPath = realpath($path);

        if ($realBase === false || $realPath === false || ($realPath !== $realBase && !str_starts_with($realPath, $realBase . '/'))) {
            throw new RuntimeException('Admin template resolves outside the admin views directory.');
        }

        return $realPath;
    }

    private function isSafeHtmlSlot(string $key): bool
    {
        return in_array($key, $this->safeHtmlSlots, true) || str_ends_with($key, '_html');
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
