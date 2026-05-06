<?php

declare(strict_types=1);

namespace PlainCMS\Http\Controllers;

use PlainCMS\Core\Request;
use PlainCMS\Core\Response;
use PlainCMS\Core\SettingsService;

final class HomeController
{
    public function __construct(
        private readonly SettingsService $settings
    ) {
    }

    /**
     * @param array<string, string> $parameters
     */
    public function index(Request $request, array $parameters = []): Response
    {
        $site = $this->settings->siteContext()->toArray();
        $title = htmlspecialchars($site['site']['title'], ENT_QUOTES, 'UTF-8');
        $description = htmlspecialchars($site['site']['description'], ENT_QUOTES, 'UTF-8');
        $theme = htmlspecialchars($site['theme']['active'], ENT_QUOTES, 'UTF-8');

        return Response::html(<<<HTML
<main style="max-width:860px;margin:48px auto;padding:0 20px;font-family:Georgia, 'Times New Roman', serif;">
    <p style="letter-spacing:0.14em;text-transform:uppercase;color:#8a7254;">MidexCMS</p>
    <h1>{$title}</h1>
    <p>{$description}</p>
    <p style="color:#6b7280;">Active theme: {$theme}. Public theme rendering arrives in Phase 7, but site context is already flowing through controlled settings.</p>
</main>
HTML);
    }

    /**
     * @param array<string, string> $parameters
     */
    public function notFound(Request $request, array $parameters = []): Response
    {
        return Response::html('<h1>404 Not Found</h1>', 404);
    }
}
