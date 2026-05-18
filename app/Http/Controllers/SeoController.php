<?php

declare(strict_types=1);

namespace MidexCMS\Http\Controllers;

use MidexCMS\Core\Request;
use MidexCMS\Core\Response;
use MidexCMS\Modules\SEO\SeoService;

final class SeoController
{
    public function __construct(
        private readonly SeoService $seo
    ) {
    }

    public function sitemap(Request $request, array $parameters = []): Response
    {
        return new Response($this->seo->sitemapXml(), 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    public function robots(Request $request, array $parameters = []): Response
    {
        return new Response($this->seo->robotsTxt(), 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
