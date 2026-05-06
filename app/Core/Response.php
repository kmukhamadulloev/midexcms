<?php

declare(strict_types=1);

namespace PlainCMS\Core;

final class Response
{
    public function __construct(
        private readonly string $content = '',
        private readonly int $status = 200,
        private readonly array $headers = []
    ) {
    }

    public static function html(string $content, int $status = 200, array $headers = []): self
    {
        return new self($content, $status, ['Content-Type' => 'text/html; charset=UTF-8'] + $headers);
    }

    public static function json(array $payload, int $status = 200, array $headers = []): self
    {
        $content = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return new self($content, $status, ['Content-Type' => 'application/json; charset=UTF-8'] + $headers);
    }

    public static function redirect(string $location, int $status = 302, array $headers = []): self
    {
        return new self('', $status, ['Location' => $location] + $headers);
    }

    public function send(): void
    {
        http_response_code($this->status);

        foreach ($this->headers as $name => $value) {
            header(sprintf('%s: %s', $name, $value), true);
        }

        echo $this->content;
    }

    public function content(): string
    {
        return $this->content;
    }

    public function status(): int
    {
        return $this->status;
    }
}
