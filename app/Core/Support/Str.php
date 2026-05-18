<?php

declare(strict_types=1);

namespace MidexCMS\Core\Support;

final class Str
{
    public static function startsWith(string $haystack, string $needle): bool
    {
        return str_starts_with($haystack, $needle);
    }

    public static function slug(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/i', '-', $value) ?? '';

        return trim($value, '-');
    }
}
