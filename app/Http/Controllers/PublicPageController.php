<?php

declare(strict_types=1);

namespace MidexCMS\Http\Controllers;

use MidexCMS\Core\Request;
use MidexCMS\Core\Response;
use MidexCMS\Core\Theme\ThemeRenderer;
use MidexCMS\Modules\Analytics\AnalyticsService;
use MidexCMS\Modules\Pages\PageService;

final class PublicPageController
{
    public function __construct(
        private readonly PageService $pages,
        private readonly AnalyticsService $analytics,
        private readonly ThemeRenderer $themes
    ) {
    }

    public function show(Request $request, array $parameters = []): Response
    {
        $path = '/' . trim((string) ($parameters['path'] ?? ''), '/');
        $path = $path === '/' ? '/' : rtrim($path, '/');
        $page = $this->pages->publishedByPath($path);

        if ($page === null) {
            return Response::html('<h1>404 Not Found</h1>', 404);
        }

        $this->analytics->recordPageView(
            $page,
            $path,
            (string) $request->server('REMOTE_ADDR', 'unknown'),
            (string) $request->server('HTTP_USER_AGENT', 'unknown'),
            (string) $request->server('HTTP_REFERER', '')
        );

        return Response::html($this->themes->renderPage($page, $request, false));
    }

    public function preview(Request $request, array $parameters = []): Response
    {
        $token = (string) ($parameters['token'] ?? '');
        $page = $this->pages->previewByToken($token);

        if ($page === null) {
            return Response::html('<h1>404 Not Found</h1>', 404);
        }

        return Response::html($this->themes->renderPage($page, $request, true));
    }
}
