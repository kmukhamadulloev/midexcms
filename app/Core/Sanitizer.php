<?php

declare(strict_types=1);

namespace PlainCMS\Core;

final class Sanitizer
{
    /**
     * @param array<int, string> $allowedTags
     */
    public function html(string $value, array $allowedTags = ['p', 'a', 'strong', 'em', 'ul', 'ol', 'li', 'blockquote', 'code', 'pre', 'br', 'h1', 'h2', 'h3', 'h4']): string
    {
        $tags = '<' . implode('><', $allowedTags) . '>';
        $sanitized = strip_tags($value, $tags);
        $sanitized = preg_replace('/\son\w+\s*=\s*(".*?"|\'.*?\'|[^\s>]+)/i', '', $sanitized) ?? '';
        $sanitized = preg_replace('/\s(href|src)\s*=\s*("|\')\s*javascript:[^"\']*("|\')/i', '', $sanitized) ?? '';

        return trim($sanitized);
    }

    public function text(string $value): string
    {
        return trim(strip_tags($value));
    }
}
