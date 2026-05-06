<?php

declare(strict_types=1);

namespace PlainCMS\Core;

final class Logger
{
    public function __construct(
        private readonly string $logPath
    ) {
    }

    public function error(string $message): void
    {
        $this->write('ERROR', $message);
    }

    public function info(string $message): void
    {
        $this->write('INFO', $message);
    }

    private function write(string $level, string $message): void
    {
        $directory = dirname($this->logPath);

        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $entry = sprintf("[%s] %s %s\n", date('Y-m-d H:i:s'), $level, $message);
        file_put_contents($this->logPath, $entry, FILE_APPEND);
    }
}
