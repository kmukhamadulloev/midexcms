<?php

declare(strict_types=1);

namespace PlainCMS\Core;

use Throwable;

final class ErrorHandler
{
    public function __construct(
        private readonly bool $debug,
        private readonly Logger $logger
    ) {
    }

    public function register(): void
    {
        set_exception_handler(function (Throwable $exception): void {
            $this->logger->error($exception->__toString());

            Response::html($this->renderException($exception), 500)->send();
        });
    }

    public function renderException(Throwable $exception): string
    {
        if ($this->debug) {
            return sprintf(
                "<h1>Application Error</h1><p>%s</p><pre>%s</pre>",
                htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($exception->getTraceAsString(), ENT_QUOTES, 'UTF-8'),
            );
        }

        return '<h1>Application Error</h1><p>Something went wrong.</p>';
    }
}
