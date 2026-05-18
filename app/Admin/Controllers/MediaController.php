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
use MidexCMS\Core\Sanitizer;
use MidexCMS\Modules\Media\MediaService;
use RuntimeException;

final class MediaController
{
    public function __construct(
        private readonly Auth $auth,
        private readonly Csrf $csrf,
        private readonly Flash $flash,
        private readonly Sanitizer $sanitizer,
        private readonly MediaService $media,
        private readonly AdminView $view,
    ) {
    }

    public function index(Request $request, array $parameters = []): Response
    {
        if (!$this->auth->check()) {
            $this->flash->error('Please sign in to manage media.');

            return Response::redirect('/admin/login');
        }

        $content = $this->view->renderMediaPage(
            $this->media->listMedia(),
            $this->csrf->inputName(),
            $this->csrf->token(),
        );

        return Response::html($this->view->layout('Media', $content, AdminNavigation::items(), $this->flash->pull()));
    }

    public function upload(Request $request, array $parameters = []): Response
    {
        if (!$this->auth->check()) {
            $this->flash->error('Please sign in to manage media.');

            return Response::redirect('/admin/login');
        }

        if (!$this->csrf->verifyToken((string) $request->input($this->csrf->inputName(), ''))) {
            $this->flash->error('CSRF verification failed.');

            return Response::redirect('/admin/media');
        }

        try {
            $this->media->upload($_FILES['media_file'] ?? [], $this->sanitizer->text((string) $request->input('alt', '')));
            $this->flash->success('Image uploaded.');
        } catch (RuntimeException $exception) {
            $this->flash->error($exception->getMessage());
        }

        return Response::redirect('/admin/media');
    }

    public function update(Request $request, array $parameters = []): Response
    {
        if (!$this->auth->check()) {
            $this->flash->error('Please sign in to manage media.');

            return Response::redirect('/admin/login');
        }

        if (!$this->csrf->verifyToken((string) $request->input($this->csrf->inputName(), ''))) {
            $this->flash->error('CSRF verification failed.');

            return Response::redirect('/admin/media');
        }

        try {
            $this->media->updateAlt((int) ($parameters['id'] ?? 0), $this->sanitizer->text((string) $request->input('alt', '')));
            $this->flash->success('Alt text updated.');
        } catch (RuntimeException $exception) {
            $this->flash->error($exception->getMessage());
        }

        return Response::redirect('/admin/media');
    }

    public function delete(Request $request, array $parameters = []): Response
    {
        if (!$this->auth->check()) {
            $this->flash->error('Please sign in to manage media.');

            return Response::redirect('/admin/login');
        }

        if (!$this->csrf->verifyToken((string) $request->input($this->csrf->inputName(), ''))) {
            $this->flash->error('CSRF verification failed.');

            return Response::redirect('/admin/media');
        }

        try {
            $this->media->delete((int) ($parameters['id'] ?? 0));
            $this->flash->success('Media item deleted.');
        } catch (RuntimeException $exception) {
            $this->flash->error($exception->getMessage());
        }

        return Response::redirect('/admin/media');
    }
}
