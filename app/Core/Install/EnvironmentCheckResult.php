<?php

declare(strict_types=1);

namespace MidexCMS\Core\Install;

final class EnvironmentCheckResult
{
    /**
     * @param array<int, array{name: string, ok: bool, detail: string}> $checks
     */
    public function __construct(
        private readonly array $checks
    ) {
    }

    /**
     * @return array<int, array{name: string, ok: bool, detail: string}>
     */
    public function checks(): array
    {
        return $this->checks;
    }

    public function passes(): bool
    {
        foreach ($this->checks as $check) {
            if ($check['ok'] !== true) {
                return false;
            }
        }

        return true;
    }
}
