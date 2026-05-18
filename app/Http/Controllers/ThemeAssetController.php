<?php

declare(strict_types=1);

namespace MidexCMS\Http\Controllers;

use MidexCMS\Core\Request;
use MidexCMS\Core\Response;
use MidexCMS\Core\Theme\ThemeLoader;
use RuntimeException;

final class ThemeAssetController
{
    public function __construct(
        private readonly ThemeLoader $themes
    ) {
    }

    public function show(Request $request, array $parameters = []): Response
    {
        $theme = (string) ($parameters['theme'] ?? '');
        $asset = (string) ($parameters['asset'] ?? '');

        try {
            $path = $this->themes->resolveAsset($theme, $asset);
        } catch (RuntimeException) {
            return Response::html('<h1>404 Not Found</h1>', 404);
        }

        if (!is_file($path)) {
            return Response::html('<h1>404 Not Found</h1>', 404);
        }

        $mime = match (pathinfo($path, PATHINFO_EXTENSION)) {
            'css' => 'text/css; charset=UTF-8',
            'js' => 'application/javascript; charset=UTF-8',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            default => 'application/octet-stream',
        };

        return new Response((string) file_get_contents($path), 200, ['Content-Type' => $mime]);
    }
}
