<?php

declare(strict_types=1);

namespace MidexCMS\Core\Cache;

final class NullCache implements CacheInterface
{
    public function get(string $key, mixed $default = null): mixed
    {
        return $default;
    }

    public function put(string $key, mixed $value, int $ttlSeconds = 3600): void
    {
    }

    public function has(string $key): bool
    {
        return false;
    }

    public function forget(string $key): void
    {
    }

    public function flush(): void
    {
    }
}
