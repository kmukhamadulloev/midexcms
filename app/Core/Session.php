<?php

declare(strict_types=1);

namespace PlainCMS\Core;

final class Session
{
    public function __construct(
        private readonly string $name
    ) {
    }

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_name($this->name);
        session_start([
            'cookie_httponly' => true,
            'cookie_secure' => $this->isHttps(),
            'cookie_samesite' => 'Lax',
            'use_strict_mode' => true,
        ]);
    }

    public function regenerate(): void
    {
        $this->start();
        session_regenerate_id(true);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->start();

        return $_SESSION[$key] ?? $default;
    }

    public function put(string $key, mixed $value): void
    {
        $this->start();
        $_SESSION[$key] = $value;
    }

    public function forget(string $key): void
    {
        $this->start();
        unset($_SESSION[$key]);
    }

    public function invalidate(): void
    {
        $this->start();
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
        }

        session_destroy();
    }

    private function isHttps(): bool
    {
        $https = $_SERVER['HTTPS'] ?? '';

        return $https === 'on' || $https === '1';
    }
}
