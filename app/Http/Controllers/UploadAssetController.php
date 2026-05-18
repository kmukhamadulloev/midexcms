<?php

declare(strict_types=1);

namespace MidexCMS\Http\Controllers;

use MidexCMS\Core\Request;
use MidexCMS\Core\Response;
use MidexCMS\Modules\Media\MediaService;
use RuntimeException;

final class UploadAssetController
{
    public function __construct(
        private readonly MediaService $media
    ) {
    }

    public function show(Request $request, array $parameters = []): Response
    {
        $relativePath = (string) ($parameters['path'] ?? '');

        try {
            $path = $this->media->resolveUploadPath($relativePath);
        } catch (RuntimeException) {
            return Response::html('<h1>404 Not Found</h1>', 404);
        }

        if (!is_file($path)) {
            return Response::html('<h1>404 Not Found</h1>', 404);
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo !== false ? finfo_file($finfo, $path) : 'application/octet-stream';

        if ($finfo !== false) {
            finfo_close($finfo);
        }

        return new Response((string) file_get_contents($path), 200, ['Content-Type' => (string) $mime]);
    }
}
