<?php

declare(strict_types=1);

namespace PlainCMS\Admin\Controllers;

use PlainCMS\Core\Admin\AdminNavigation;
use PlainCMS\Core\Admin\AdminView;
use PlainCMS\Core\Auth;
use PlainCMS\Core\Csrf;
use PlainCMS\Core\Flash;
use PlainCMS\Core\Request;
use PlainCMS\Core\Response;
use PlainCMS\Core\Sanitizer;
use PlainCMS\Core\Validator;
use PlainCMS\Modules\Pages\PageService;
use RuntimeException;

final class PageController
{
    public function __construct(
        private readonly Auth $auth,
        private readonly Csrf $csrf,
        private readonly Flash $flash,
        private readonly Validator $validator,
        private readonly Sanitizer $sanitizer,
        private readonly PageService $pages,
        private readonly AdminView $view
    ) {
    }

    public function index(Request $request, array $parameters = []): Response
    {
        if (!$this->auth->check()) {
            $this->flash->error('Please sign in to manage pages.');

            return Response::redirect('/admin/login');
        }

        $content = $this->view->renderPagesIndexPage(
            $this->pages->listPages(),
            $this->csrf->inputName(),
            $this->csrf->token(),
            fn (int $id): string => $this->pages->previewToken($id),
        );

        return Response::html($this->view->layout('Pages', $content, AdminNavigation::items(), $this->flash->pull()));
    }

    public function create(Request $request, array $parameters = []): Response
    {
        return $this->editorPage('Create Page', null);
    }

    public function store(Request $request, array $parameters = []): Response
    {
        return $this->persist($request, null);
    }

    public function edit(Request $request, array $parameters = []): Response
    {
        return $this->editorPage('Edit Page', isset($parameters['id']) ? (int) $parameters['id'] : null);
    }

    public function update(Request $request, array $parameters = []): Response
    {
        return $this->persist($request, isset($parameters['id']) ? (int) $parameters['id'] : null);
    }

    public function delete(Request $request, array $parameters = []): Response
    {
        return $this->statusAction($request, (int) ($parameters['id'] ?? 0), 'delete');
    }

    public function publish(Request $request, array $parameters = []): Response
    {
        return $this->statusAction($request, (int) ($parameters['id'] ?? 0), 'publish');
    }

    public function archive(Request $request, array $parameters = []): Response
    {
        return $this->statusAction($request, (int) ($parameters['id'] ?? 0), 'archive');
    }

    private function editorPage(string $title, ?int $pageId): Response
    {
        if (!$this->auth->check()) {
            $this->flash->error('Please sign in to manage pages.');

            return Response::redirect('/admin/login');
        }

        $page = $pageId !== null ? $this->pages->findPage($pageId) : null;

        if ($pageId !== null && $page === null) {
            $this->flash->error('Page not found.');

            return Response::redirect('/admin/pages');
        }

        $old = $this->flash->pullOldInput();
        $errors = $this->flash->pullErrors();
        $values = $old !== [] ? $old : $this->formValues($page);
        $content = $this->view->renderPageEditorPage(
            $values,
            $errors,
            $pageId === null ? '/admin/pages' : '/admin/pages/' . $pageId,
            $pageId,
            $pageId !== null ? '/preview/' . rawurlencode($this->pages->previewToken($pageId)) : '',
            $this->pages->parentOptions($pageId),
            $this->csrf->inputName(),
            $this->csrf->token(),
        );

        return Response::html($this->view->layout($title, $content, AdminNavigation::items(), $this->flash->pull()));
    }

    private function persist(Request $request, ?int $pageId): Response
    {
        if (!$this->auth->check()) {
            $this->flash->error('Please sign in to manage pages.');

            return Response::redirect('/admin/login');
        }

        if (!$this->csrf->verifyToken((string) $request->input($this->csrf->inputName(), ''))) {
            $this->flash->error('CSRF verification failed.');

            return Response::redirect('/admin/pages');
        }

        $input = [
            'parent_id' => (string) $request->input('parent_id', ''),
            'title' => $this->sanitizer->text((string) $request->input('title', '')),
            'slug' => $this->sanitizer->text((string) $request->input('slug', '')),
            'path' => trim((string) $request->input('path', '')),
            'excerpt' => $this->sanitizer->text((string) $request->input('excerpt', '')),
            'content_mode' => (string) $request->input('content_mode', 'markdown'),
            'content_raw' => (string) $request->input('content_raw', ''),
            'template' => $this->sanitizer->text((string) $request->input('template', '')),
            'seo_title' => $this->sanitizer->text((string) $request->input('seo_title', '')),
            'seo_description' => $this->sanitizer->text((string) $request->input('seo_description', '')),
            'seo_keywords' => $this->sanitizer->text((string) $request->input('seo_keywords', '')),
            'comments_enabled' => (string) $request->input('comments_enabled', ''),
            'status' => (string) $request->input('status', 'draft'),
        ];

        $errors = $this->validator->validate($input, [
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['string', 'max:190'],
            'path' => ['string', 'max:500'],
            'content_mode' => ['required', 'string', 'in:markdown,html'],
            'template' => ['string', 'max:100'],
            'seo_title' => ['string', 'max:255'],
            'seo_description' => ['string', 'max:500'],
            'seo_keywords' => ['string', 'max:500'],
            'status' => ['required', 'string', 'in:draft,published,archived'],
        ]);

        if ($input['content_raw'] === '') {
            $errors['content_raw'][] = 'Content is required.';
        }

        if ($errors !== []) {
            $this->flash->withErrors($errors);
            $this->flash->withOldInput($input);
            $this->flash->error('Please fix the page form errors.');

            return Response::redirect($pageId === null ? '/admin/pages/create' : '/admin/pages/' . $pageId . '/edit');
        }

        try {
            if ($pageId === null) {
                $pageId = $this->pages->create($input);
                $this->flash->success('Page created.');

                return Response::redirect('/admin/pages/' . $pageId . '/edit');
            }

            $this->pages->update($pageId, $input);
            $this->flash->success('Page updated.');
        } catch (RuntimeException $exception) {
            $this->flash->withOldInput($input);
            $this->flash->error($exception->getMessage());

            return Response::redirect($pageId === null ? '/admin/pages/create' : '/admin/pages/' . $pageId . '/edit');
        }

        return Response::redirect('/admin/pages/' . $pageId . '/edit');
    }

    private function statusAction(Request $request, int $pageId, string $action): Response
    {
        if (!$this->auth->check()) {
            $this->flash->error('Please sign in to manage pages.');

            return Response::redirect('/admin/login');
        }

        if (!$this->csrf->verifyToken((string) $request->input($this->csrf->inputName(), ''))) {
            $this->flash->error('CSRF verification failed.');

            return Response::redirect('/admin/pages');
        }

        try {
            match ($action) {
                'delete' => $this->pages->delete($pageId),
                'publish' => $this->pages->publish($pageId),
                'archive' => $this->pages->archive($pageId),
            };
        } catch (RuntimeException $exception) {
            $this->flash->error($exception->getMessage());

            return Response::redirect('/admin/pages');
        }

        $message = match ($action) {
            'delete' => 'Page deleted.',
            'publish' => 'Page published.',
            default => 'Page archived.',
        };

        $this->flash->success($message);

        return Response::redirect('/admin/pages');
    }

    /**
     * @return array<string, mixed>
     */
    private function formValues(?array $page): array
    {
        if ($page === null) {
            return [
                'parent_id' => '',
                'title' => '',
                'slug' => '',
                'path' => '',
                'excerpt' => '',
                'content_mode' => 'markdown',
                'content_raw' => '',
                'template' => '',
                'seo_title' => '',
                'seo_description' => '',
                'seo_keywords' => '',
                'comments_enabled' => '',
                'status' => 'draft',
            ];
        }

        return [
            'parent_id' => $page['parent_id'] !== null ? (string) $page['parent_id'] : '',
            'title' => (string) $page['title'],
            'slug' => (string) $page['slug'],
            'path' => (string) $page['path'],
            'excerpt' => (string) ($page['excerpt'] ?? ''),
            'content_mode' => (string) $page['content_mode'],
            'content_raw' => (string) ($page['content_raw'] ?? ''),
            'template' => (string) ($page['template'] ?? ''),
            'seo_title' => (string) ($page['seo_title'] ?? ''),
            'seo_description' => (string) ($page['seo_description'] ?? ''),
            'seo_keywords' => (string) ($page['seo_keywords'] ?? ''),
            'comments_enabled' => $page['comments_enabled'] === null ? '' : ((bool) $page['comments_enabled'] ? '1' : '0'),
            'status' => (string) $page['status'],
        ];
    }
}
