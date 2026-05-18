<?php

declare(strict_types=1);

namespace MidexCMS\Core;

use MidexCMS\Core\Cache\CacheInterface;

final class RateLimiter
{
    public function __construct(
        private readonly CacheInterface $cache
    ) {
    }

    public function tooManyAttempts(string $key, int $maxAttempts): bool
    {
        return $this->attempts($key) >= $maxAttempts;
    }

    public function hit(string $key, int $decaySeconds): int
    {
        $entry = $this->entry($key);

        if ($entry === null || $entry['expires_at'] <= time()) {
            $entry = [
                'count' => 0,
                'expires_at' => time() + $decaySeconds,
            ];
        }

        $entry['count']++;

        $ttl = max(1, $entry['expires_at'] - time());
        $this->cache->put($this->cacheKey($key), $entry, $ttl);

        return $entry['count'];
    }

    public function clear(string $key): void
    {
        $this->cache->forget($this->cacheKey($key));
    }

    public function attempts(string $key): int
    {
        $entry = $this->entry($key);

        if ($entry === null || $entry['expires_at'] <= time()) {
            return 0;
        }

        return (int) $entry['count'];
    }

    public function availableIn(string $key): int
    {
        $entry = $this->entry($key);

        if ($entry === null || $entry['expires_at'] <= time()) {
            return 0;
        }

        return max(0, (int) $entry['expires_at'] - time());
    }

    /**
     * @return array{count: int, expires_at: int}|null
     */
    private function entry(string $key): ?array
    {
        $entry = $this->cache->get($this->cacheKey($key));

        if (!is_array($entry) || !isset($entry['count'], $entry['expires_at'])) {
            return null;
        }

        return [
            'count' => (int) $entry['count'],
            'expires_at' => (int) $entry['expires_at'],
        ];
    }

    private function cacheKey(string $key): string
    {
        return 'rate_limit:' . sha1($key);
    }
}
