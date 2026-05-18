<?php

declare(strict_types=1);

namespace MidexCMS\Core\Support;

final class Arr
{
    public static function get(array $items, string $key, mixed $default = null): mixed
    {
        if ($key === '') {
            return $items;
        }

        $segments = explode('.', $key);
        $value = $items;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }
}
