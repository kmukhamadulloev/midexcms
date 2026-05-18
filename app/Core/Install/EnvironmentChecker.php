<?php

declare(strict_types=1);

namespace MidexCMS\Core\Install;

final class EnvironmentChecker
{
    /**
     * @param array<int, string> $writablePaths
     */
    public function __construct(
        private readonly string $rootPath,
        private readonly array $writablePaths
    ) {
    }

    public function run(): EnvironmentCheckResult
    {
        $checks = [];
        $checks[] = $this->checkPhpVersion();
        $checks[] = $this->extensionCheck('PDO', extension_loaded('pdo'), extension_loaded('pdo') ? 'Loaded' : 'Missing');
        $checks[] = $this->extensionCheck('pdo_pgsql', extension_loaded('pdo_pgsql'), extension_loaded('pdo_pgsql') ? 'Loaded' : 'Missing');

        foreach ($this->writablePaths as $path) {
            $checks[] = $this->checkWritablePath($path);
        }

        return new EnvironmentCheckResult($checks);
    }

    /**
     * @return array{name: string, ok: bool, detail: string}
     */
    private function checkPhpVersion(): array
    {
        $required = '8.4.0';
        $current = PHP_VERSION;

        return [
            'name' => 'PHP version',
            'ok' => version_compare($current, $required, '>='),
            'detail' => sprintf('Current: %s, required: %s+', $current, $required),
        ];
    }

    /**
     * @return array{name: string, ok: bool, detail: string}
     */
    private function extensionCheck(string $name, bool $ok, string $detail): array
    {
        return [
            'name' => $name,
            'ok' => $ok,
            'detail' => $detail,
        ];
    }

    /**
     * @return array{name: string, ok: bool, detail: string}
     */
    private function checkWritablePath(string $relativePath): array
    {
        $absolutePath = $this->rootPath . '/' . ltrim($relativePath, '/');
        $exists = is_dir($absolutePath) || is_file($absolutePath);
        $ok = $exists && is_writable($absolutePath);

        return [
            'name' => sprintf('Writable: %s', $relativePath),
            'ok' => $ok,
            'detail' => $ok ? 'Writable' : 'Not writable or missing',
        ];
    }
}
