<?php

declare(strict_types=1);

namespace PlainCMS\Core\Cache;

use RuntimeException;

final class FileCache implements CacheInterface
{
    public function __construct(
        private readonly string $directory
    ) {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $path = $this->pathFor($key);

        if (!is_file($path)) {
            return $default;
        }

        $payload = json_decode((string) file_get_contents($path), true);

        if (!is_array($payload) || !array_key_exists('expires_at', $payload) || !array_key_exists('value', $payload)) {
            $this->forget($key);

            return $default;
        }

        if ((int) $payload['expires_at'] !== 0 && (int) $payload['expires_at'] < time()) {
            $this->forget($key);

            return $default;
        }

        return $payload['value'];
    }

    public function put(string $key, mixed $value, int $ttlSeconds = 3600): void
    {
        $this->ensureDirectory();

        $payload = [
            'expires_at' => $ttlSeconds > 0 ? time() + $ttlSeconds : 0,
            'value' => $value,
        ];

        $written = file_put_contents($this->pathFor($key), json_encode($payload, JSON_THROW_ON_ERROR));

        if ($written === false) {
            throw new RuntimeException('Failed to write cache item.');
        }
    }

    public function has(string $key): bool
    {
        return $this->get($key, '__plaincms_missing__') !== '__plaincms_missing__';
    }

    public function forget(string $key): void
    {
        $path = $this->pathFor($key);

        if (is_file($path)) {
            unlink($path);
        }
    }

    public function flush(): void
    {
        $this->ensureDirectory();

        foreach (glob($this->directory . '/*.cache') ?: [] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    private function pathFor(string $key): string
    {
        return rtrim($this->directory, '/') . '/' . sha1($key) . '.cache';
    }

    private function ensureDirectory(): void
    {
        if (is_dir($this->directory)) {
            return;
        }

        if (!mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new RuntimeException(sprintf('Failed to create cache directory: %s', $this->directory));
        }
    }
}
