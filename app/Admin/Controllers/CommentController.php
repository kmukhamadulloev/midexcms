<?php

declare(strict_types=1);

namespace MidexCMS\Admin\Controllers;

use MidexCMS\Core\Admin\AdminNavigation;
use MidexCMS\Core\Admin\AdminView;
use MidexCMS\Core\Auth;
use MidexCMS\Core\Csrf;
use MidexCMS\Core\Flash;
use MidexCMS\Core\Request;
use MidexCMS\Core\Response;
use MidexCMS\Modules\Comments\CommentService;
use RuntimeException;

final class CommentController
{
    public function __construct(
        private readonly Auth $auth,
        private readonly Csrf $csrf,
        private readonly Flash $flash,
        private readonly CommentService $comments,
        private readonly AdminView $view
    ) {
    }

    public function index(Request $request, array $parameters = []): Response
    {
        if (!$this->auth->check()) {
            $this->flash->error('Please sign in to manage comments.');

            return Response::redirect('/admin/login');
        }

        $content = $this->view->renderCommentsPage(
            $this->comments->adminList(),
            $this->csrf->inputName(),
            $this->csrf->token(),
        );

        return Response::html($this->view->layout('Comments', $content, AdminNavigation::items(), $this->flash->pull()));
    }

    public function approve(Request $request, array $parameters = []): Response
    {
        return $this->moderate($request, (int) ($parameters['id'] ?? 0), 'approve');
    }

    public function spam(Request $request, array $parameters = []): Response
    {
        return $this->moderate($request, (int) ($parameters['id'] ?? 0), 'spam');
    }

    public function delete(Request $request, array $parameters = []): Response
    {
        return $this->moderate($request, (int) ($parameters['id'] ?? 0), 'delete');
    }

    private function moderate(Request $request, int $id, string $action): Response
    {
        if (!$this->auth->check()) {
            $this->flash->error('Please sign in to manage comments.');

            return Response::redirect('/admin/login');
        }

        if (!$this->csrf->verifyToken((string) $request->input($this->csrf->inputName(), ''))) {
            $this->flash->error('CSRF verification failed.');

            return Response::redirect('/admin/comments');
        }

        try {
            match ($action) {
                'approve' => $this->comments->approve($id),
                'spam' => $this->comments->spam($id),
                'delete' => $this->comments->delete($id),
            };
            $this->flash->success('Comment updated.');
        } catch (RuntimeException $exception) {
            $this->flash->error($exception->getMessage());
        }

        return Response::redirect('/admin/comments');
    }
}
