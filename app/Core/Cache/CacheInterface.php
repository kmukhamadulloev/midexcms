<?php

declare(strict_types=1);

namespace MidexCMS\Core\Cache;

interface CacheInterface
{
    public function get(string $key, mixed $default = null): mixed;

    public function put(string $key, mixed $value, int $ttlSeconds = 3600): void;

    public function has(string $key): bool;

    public function forget(string $key): void;

    public function flush(): void;
}
