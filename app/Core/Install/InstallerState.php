<?php

declare(strict_types=1);

namespace PlainCMS\Core\Install;

use PlainCMS\Core\Session;

final class InstallerState
{
    private const KEY = 'installer';

    public function __construct(
        private readonly Session $session
    ) {
    }

    public function all(): array
    {
        $state = $this->session->get(self::KEY, []);

        return is_array($state) ? $state : [];
    }

    public function put(string $key, array $value): void
    {
        $state = $this->all();
        $state[$key] = $value;
        $this->session->put(self::KEY, $state);
    }

    public function get(string $key, array $default = []): array
    {
        $state = $this->all();
        $value = $state[$key] ?? $default;

        return is_array($value) ? $value : $default;
    }

    public function clear(): void
    {
        $this->session->forget(self::KEY);
    }

    public function readyForFinish(): bool
    {
        $state = $this->all();

        return isset($state['checks'], $state['database'], $state['admin'], $state['site']);
    }
}
