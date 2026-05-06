<?php

declare(strict_types=1);

namespace PlainCMS\Core;

use RuntimeException;

final class Config
{
    private array $items;

    private function __construct(array $items)
    {
        $this->items = $items;
    }

    public static function load(string $rootPath): self
    {
        $configPath = $rootPath . '/config/config.php';
        $fallbackPath = $rootPath . '/config/config.php.example';
        $path = is_file($configPath) ? $configPath : $fallbackPath;

        if (!is_file($path)) {
            throw new RuntimeException(sprintf('Configuration file not found: %s', $path));
        }

        $items = require $path;

        if (!is_array($items)) {
            throw new RuntimeException(sprintf('Configuration file must return an array: %s', $path));
        }

        return new self($items);
    }

    public function all(): array
    {
        return $this->items;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if ($key === '') {
            return $this->items;
        }

        $segments = explode('.', $key);
        $value = $this->items;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }
}
