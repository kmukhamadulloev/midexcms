<?php

declare(strict_types=1);

namespace MidexCMS\Core;

final class Flash
{
    private const FLASH_KEY = '_flash';
    private const OLD_KEY = '_old';
    private const ERRORS_KEY = '_errors';

    public function __construct(
        private readonly Session $session
    ) {
    }

    public function success(string $message): void
    {
        $this->message('success', $message);
    }

    public function error(string $message): void
    {
        $this->message('error', $message);
    }

    public function message(string $type, string $message): void
    {
        $this->session->put(self::FLASH_KEY, [
            'type' => $type,
            'message' => $message,
        ]);
    }

    public function pull(): ?array
    {
        $flash = $this->session->get(self::FLASH_KEY);
        $this->session->forget(self::FLASH_KEY);

        return is_array($flash) ? $flash : null;
    }

    /**
     * @param array<string, mixed> $input
     */
    public function withOldInput(array $input): void
    {
        $this->session->put(self::OLD_KEY, $input);
    }

    /**
     * @return array<string, mixed>
     */
    public function pullOldInput(): array
    {
        $old = $this->session->get(self::OLD_KEY, []);
        $this->session->forget(self::OLD_KEY);

        return is_array($old) ? $old : [];
    }

    /**
     * @param array<string, array<int, string>> $errors
     */
    public function withErrors(array $errors): void
    {
        $this->session->put(self::ERRORS_KEY, $errors);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function pullErrors(): array
    {
        $errors = $this->session->get(self::ERRORS_KEY, []);
        $this->session->forget(self::ERRORS_KEY);

        return is_array($errors) ? $errors : [];
    }
}
