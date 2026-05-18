<?php

declare(strict_types=1);

namespace MidexCMS\Modules\Pages;

final class PagePreviewSigner
{
    public function __construct(
        private readonly string $secret
    ) {
    }

    public function sign(int $pageId): string
    {
        $expiresAt = time() + 3600;
        $payload = $pageId . '|' . $expiresAt;
        $signature = hash_hmac('sha256', $payload, $this->secret);

        return rtrim(strtr(base64_encode($payload . '|' . $signature), '+/', '-_'), '=');
    }

    public function verify(string $token): ?int
    {
        $normalized = strtr($token, '-_', '+/');
        $padding = strlen($normalized) % 4;

        if ($padding > 0) {
            $normalized .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($normalized, true);

        if ($decoded === false) {
            return null;
        }

        $parts = explode('|', $decoded);

        if (count($parts) !== 3) {
            return null;
        }

        [$pageId, $expiresAt, $signature] = $parts;
        $payload = $pageId . '|' . $expiresAt;
        $expected = hash_hmac('sha256', $payload, $this->secret);

        if (!hash_equals($expected, $signature)) {
            return null;
        }

        if ((int) $expiresAt < time()) {
            return null;
        }

        return ctype_digit($pageId) ? (int) $pageId : null;
    }
}
