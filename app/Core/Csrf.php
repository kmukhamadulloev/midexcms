<?php

declare(strict_types=1);

namespace PlainCMS\Core;

final class Csrf
{
    private const SESSION_KEY = '_csrf_token';

    public function __construct(
        private readonly Session $session,
        private readonly string $inputName
    ) {
    }

    public function token(): string
    {
        $existing = $this->session->get(self::SESSION_KEY);

        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $token = bin2hex(random_bytes(32));
        $this->session->put(self::SESSION_KEY, $token);

        return $token;
    }

    public function inputName(): string
    {
        return $this->inputName;
    }

    public function verifyToken(?string $token): bool
    {
        $known = $this->session->get(self::SESSION_KEY);

        return is_string($known) && is_string($token) && hash_equals($known, $token);
    }
}
