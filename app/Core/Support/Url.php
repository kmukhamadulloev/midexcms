<?php

declare(strict_types=1);

namespace PlainCMS\Core\Support;

final class Url
{
    public static function trimSlashes(string $value): string
    {
        return trim($value, '/');
    }

    public static function join(string ...$segments): string
    {
        $segments = array_map(static fn (string $segment): string => trim($segment, '/'), $segments);
        $segments = array_filter($segments, static fn (string $segment): bool => $segment !== '');

        return '/' . implode('/', $segments);
    }
}
