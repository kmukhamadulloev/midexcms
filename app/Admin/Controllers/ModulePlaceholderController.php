<?php

declare(strict_types=1);

namespace MidexCMS\Admin\Controllers;

use MidexCMS\Core\Admin\AdminNavigation;
use MidexCMS\Core\Admin\AdminView;
use MidexCMS\Core\Auth;
use MidexCMS\Core\Flash;
use MidexCMS\Core\Request;
use MidexCMS\Core\Response;

final class ModulePlaceholderController
{
    public function __construct(
        private readonly Auth $auth,
        private readonly Flash $flash,
        private readonly AdminView $view,
    ) {
    }

    public function show(Request $request, array $parameters = []): Response
    {
        if (!$this->auth->check()) {
            $this->flash->error('Please sign in to access that admin area.');

            return Response::redirect('/admin/login');
        }

        $section = (string) ($parameters['section'] ?? 'Area');
        $labels = $this->mapSection($section);
        $content = $this->view->renderPlaceholderPage($labels);

        return Response::html($this->view->layout($labels['title'], $content, AdminNavigation::items(), $this->flash->pull()));
    }

    /**
     * @return array{title: string, message: string, next: string}
     */
    private function mapSection(string $section): array
    {
        return match ($section) {
            'pages' => ['title' => 'Pages', 'message' => 'The page manager lands in Phase 6.', 'next' => 'Next: build CRUD, revisions, preview, and publishing workflows.'],
            'posts' => ['title' => 'Blog', 'message' => 'The blog manager lands in Phase 9.', 'next' => 'Next: layer posts on top of the shared pages table.'],
            'media' => ['title' => 'Media', 'message' => 'The media library lands in Phase 8.', 'next' => 'Next: secure uploads, listing, and insert flow.'],
            'menus' => ['title' => 'Menus', 'message' => 'The menu builder lands in Phase 8.', 'next' => 'Next: nested items and safe menu rendering.'],
            'forms' => ['title' => 'Forms', 'message' => 'The forms module lands in Phase 9.', 'next' => 'Next: field management and submission storage.'],
            'comments' => ['title' => 'Comments', 'message' => 'The moderation queue lands in Phase 9.', 'next' => 'Next: approval, spam, and delete actions.'],
            'analytics' => ['title' => 'Analytics', 'message' => 'Analytics lands in Phase 10.', 'next' => 'Next: daily aggregation and dashboard reporting.'],
            'themes' => ['title' => 'Themes', 'message' => 'Theme management lands in Phase 7.', 'next' => 'Next: theme loader, parser, and activation flow.'],
            'settings' => ['title' => 'Settings', 'message' => 'Settings management lands in Phase 5.', 'next' => 'Next: site identity, comments, uploads, cache, and active theme settings.'],
            'backups' => ['title' => 'Backups', 'message' => 'Backup management lands in Phase 10.', 'next' => 'Next: archive generation and download flow.'],
            default => ['title' => 'Admin Area', 'message' => 'This area is not ready yet.', 'next' => 'Next: continue the planned module phases.'],
        };
    }
}
