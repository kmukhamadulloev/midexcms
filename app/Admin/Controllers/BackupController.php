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
use MidexCMS\Modules\Backups\BackupService;
use RuntimeException;

final class BackupController
{
    public function __construct(
        private readonly Auth $auth,
        private readonly Csrf $csrf,
        private readonly Flash $flash,
        private readonly BackupService $backups,
        private readonly AdminView $view
    ) {
    }

    public function index(Request $request, array $parameters = []): Response
    {
        if (!$this->auth->check()) {
            $this->flash->error('Please sign in to access backups.');

            return Response::redirect('/admin/login');
        }

        $content = $this->view->renderBackupsPage(
            $this->backups->listBackups(),
            $this->csrf->inputName(),
            $this->csrf->token(),
        );

        return Response::html($this->view->layout('Backups', $content, AdminNavigation::items(), $this->flash->pull()));
    }

    public function create(Request $request, array $parameters = []): Response
    {
        if (!$this->auth->check()) {
            $this->flash->error('Please sign in to manage backups.');

            return Response::redirect('/admin/login');
        }

        if (!$this->csrf->verifyToken((string) $request->input($this->csrf->inputName(), ''))) {
            $this->flash->error('CSRF verification failed.');

            return Response::redirect('/admin/backups');
        }

        try {
            $backup = $this->backups->create();
            $this->flash->success('Backup created: ' . $backup['name']);
        } catch (RuntimeException $exception) {
            $this->flash->error($exception->getMessage());
        }

        return Response::redirect('/admin/backups');
    }

    public function download(Request $request, array $parameters = []): Response
    {
        if (!$this->auth->check()) {
            $this->flash->error('Please sign in to access backups.');

            return Response::redirect('/admin/login');
        }

        try {
            $path = $this->backups->resolveDownload((string) ($parameters['name'] ?? ''), (string) ($parameters['file'] ?? ''));
        } catch (RuntimeException) {
            return Response::html('<h1>404 Not Found</h1>', 404);
        }

        if (!is_file($path)) {
            return Response::html('<h1>404 Not Found</h1>', 404);
        }

        $mime = match (pathinfo($path, PATHINFO_EXTENSION)) {
            'json' => 'application/json; charset=UTF-8',
            'zip' => 'application/zip',
            default => 'application/octet-stream',
        };

        return new Response((string) file_get_contents($path), 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'attachment; filename="' . basename($path) . '"',
        ]);
    }
}
