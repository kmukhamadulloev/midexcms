<?php

declare(strict_types=1);

namespace PlainCMS\Modules\Pages;

use PlainCMS\Core\Sanitizer;

final class ContentRenderer
{
    public function __construct(
        private readonly Sanitizer $sanitizer
    ) {
    }

    public function render(string $mode, ?string $raw): string
    {
        return $this->renderWithTransform($mode, $raw, null);
    }

    public function renderWithTransform(string $mode, ?string $raw, ?callable $transform): string
    {
        $raw = trim((string) $raw);

        if ($raw === '') {
            return '';
        }

        $html = $mode === 'html'
            ? $raw
            : $this->renderMarkdown($raw);

        if ($transform !== null) {
            $html = $transform($html);
        }

        return $this->sanitizer->html($html, [
            'p', 'a', 'strong', 'em', 'ul', 'ol', 'li', 'blockquote', 'code', 'pre', 'br', 'h1', 'h2', 'h3', 'h4',
            'div', 'section', 'article', 'figure', 'figcaption', 'img', 'form', 'label', 'input', 'button', 'textarea',
            'select', 'option', 'small', 'time', 'span',
        ]);
    }

    private function renderMarkdown(string $markdown): string
    {
        $lines = preg_split("/\r\n|\n|\r/", $markdown) ?: [];
        $html = [];
        $inList = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                if ($inList) {
                    $html[] = '</ul>';
                    $inList = false;
                }

                continue;
            }

            if (preg_match('/^[-*]\s+(.+)$/', $trimmed, $matches) === 1) {
                if (!$inList) {
                    $html[] = '<ul>';
                    $inList = true;
                }

                $html[] = '<li>' . $this->inline($matches[1]) . '</li>';

                continue;
            }

            if ($inList) {
                $html[] = '</ul>';
                $inList = false;
            }

            if (preg_match('/^(#{1,4})\s+(.+)$/', $trimmed, $matches) === 1) {
                $level = strlen($matches[1]);
                $html[] = sprintf('<h%d>%s</h%d>', $level, $this->inline($matches[2]), $level);

                continue;
            }

            if (preg_match('/^>\s?(.+)$/', $trimmed, $matches) === 1) {
                $html[] = '<blockquote>' . $this->inline($matches[1]) . '</blockquote>';

                continue;
            }

            $html[] = '<p>' . $this->inline($trimmed) . '</p>';
        }

        if ($inList) {
            $html[] = '</ul>';
        }

        return implode("\n", $html);
    }

    private function inline(string $text): string
    {
        $escaped = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        $escaped = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $escaped) ?? $escaped;
        $escaped = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $escaped) ?? $escaped;
        $escaped = preg_replace('/`(.+?)`/', '<code>$1</code>', $escaped) ?? $escaped;
        $escaped = preg_replace('/\[(.+?)\]\((https?:\/\/[^\s)]+)\)/', '<a href="$2">$1</a>', $escaped) ?? $escaped;

        return nl2br($escaped, false);
    }
}
