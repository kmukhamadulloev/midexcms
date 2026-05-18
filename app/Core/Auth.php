<?php

declare(strict_types=1);

namespace MidexCMS\Core;

final class Auth
{
    private const SESSION_KEY = 'auth_user_id';

    public function __construct(
        private readonly Session $session
    ) {
    }

    public function login(int $userId): void
    {
        $this->session->regenerate();
        $this->session->put(self::SESSION_KEY, $userId);
    }

    public function logout(): void
    {
        $this->session->forget(self::SESSION_KEY);
        $this->session->regenerate();
    }

    public function check(): bool
    {
        return $this->id() !== null;
    }

    public function guest(): bool
    {
        return !$this->check();
    }

    public function id(): ?int
    {
        $userId = $this->session->get(self::SESSION_KEY);

        return is_numeric($userId) ? (int) $userId : null;
    }
}
