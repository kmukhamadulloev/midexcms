<?php

declare(strict_types=1);

namespace PlainCMS\Core;

use DateInterval;
use RuntimeException;

final class PasswordResetService
{
    public function __construct(
        private readonly Database $database,
        private readonly int $ttlMinutes
    ) {
    }

    public function issueForEmail(string $email): ?string
    {
        $user = $this->database->selectOne('SELECT id FROM users WHERE email = :email', [
            'email' => strtolower(trim($email)),
        ]);

        if ($user === null) {
            return null;
        }

        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $expiresAt = (new \DateTimeImmutable())->add(new DateInterval('PT' . $this->ttlMinutes . 'M'))->format('Y-m-d H:i:s');

        $this->database->statement(
            'INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (:user_id, :token_hash, :expires_at)',
            [
                'user_id' => (int) $user['id'],
                'token_hash' => $tokenHash,
                'expires_at' => $expiresAt,
            ]
        );

        return $token;
    }

    public function validate(string $token): ?array
    {
        $tokenHash = hash('sha256', $token);

        return $this->database->selectOne(
            'SELECT id, user_id, expires_at, used_at FROM password_resets WHERE token_hash = :token_hash ORDER BY id DESC LIMIT 1',
            ['token_hash' => $tokenHash]
        );
    }

    public function consume(string $token, string $newPassword): void
    {
        $reset = $this->validate($token);

        if ($reset === null) {
            throw new RuntimeException('Reset token was not found.');
        }

        if ($reset['used_at'] !== null) {
            throw new RuntimeException('Reset token has already been used.');
        }

        if (strtotime((string) $reset['expires_at']) < time()) {
            throw new RuntimeException('Reset token has expired.');
        }

        $this->database->transaction(function (Database $database) use ($reset, $newPassword): void {
            $database->statement(
                'UPDATE users SET password_hash = :password_hash, updated_at = CURRENT_TIMESTAMP WHERE id = :id',
                [
                    'id' => (int) $reset['user_id'],
                    'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                ]
            );

            $database->statement(
                'UPDATE password_resets SET used_at = CURRENT_TIMESTAMP WHERE id = :id',
                ['id' => (int) $reset['id']]
            );
        });
    }
}
