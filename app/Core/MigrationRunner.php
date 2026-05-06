<?php

declare(strict_types=1);

namespace PlainCMS\Core;

use RuntimeException;

final class MigrationRunner
{
    public function __construct(
        private readonly Database $database,
        private readonly string $migrationsPath
    ) {
    }

    public function runAll(): void
    {
        $files = glob(rtrim($this->migrationsPath, '/') . '/*.sql') ?: [];
        sort($files);

        if ($files === []) {
            throw new RuntimeException('No migration files were found.');
        }

        $this->database->transaction(function () use ($files): void {
            foreach ($files as $file) {
                $sql = trim((string) file_get_contents($file));

                if ($sql === '') {
                    continue;
                }

                $this->database->executeScript($sql);
            }
        });
    }
}
