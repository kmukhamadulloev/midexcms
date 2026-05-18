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
use MidexCMS\Modules\Forms\FormService;
use RuntimeException;

final class FormController
{
    public function __construct(
        private readonly Auth $auth,
        private readonly Csrf $csrf,
        private readonly Flash $flash,
        private readonly Sanitizer $sanitizer,
        private readonly FormService $forms,
        private readonly AdminView $view
    ) {
    }

    public function index(Request $request, array $parameters = []): Response
    {
        if (!$this->auth->check()) {
            $this->flash->error('Please sign in to manage forms.');

            return Response::redirect('/admin/login');
        }

        $content = $this->view->renderFormsIndexPage($this->forms->listForms());

        return Response::html($this->view->layout('Forms', $content, AdminNavigation::items(), $this->flash->pull()));
    }

    public function create(Request $request, array $parameters = []): Response
    {
        return $this->editor('Create Form', null);
    }

    public function store(Request $request, array $parameters = []): Response
    {
        return $this->persist($request, null);
    }

    public function edit(Request $request, array $parameters = []): Response
    {
        return $this->editor('Edit Form', (int) ($parameters['id'] ?? 0));
    }

    public function update(Request $request, array $parameters = []): Response
    {
        return $this->persist($request, (int) ($parameters['id'] ?? 0));
    }

    public function submissions(Request $request, array $parameters = []): Response
    {
        if (!$this->auth->check()) {
            $this->flash->error('Please sign in to manage form submissions.');

            return Response::redirect('/admin/login');
        }

        $form = $this->forms->findForm((int) ($parameters['id'] ?? 0));

        if ($form === null) {
            $this->flash->error('Form not found.');

            return Response::redirect('/admin/forms');
        }

        $content = $this->view->renderFormSubmissionsPage(
            $form,
            $this->forms->submissions((int) $form['id']),
        );

        return Response::html($this->view->layout('Form Submissions', $content, AdminNavigation::items(), $this->flash->pull()));
    }

    private function editor(string $title, ?int $id): Response
    {
        if (!$this->auth->check()) {
            $this->flash->error('Please sign in to manage forms.');

            return Response::redirect('/admin/login');
        }

        $form = $id !== null ? $this->forms->findForm($id) : null;

        if ($id !== null && $form === null) {
            $this->flash->error('Form not found.');

            return Response::redirect('/admin/forms');
        }

        $values = $form ?? [
            'name' => '',
            'key' => '',
            'description' => '',
            'success_message' => '',
            'send_email' => false,
            'email_to' => '',
            'save_submissions' => true,
            'captcha_enabled' => false,
            'is_active' => true,
            'fields' => [
                ['type' => 'text', 'name' => 'name', 'label' => 'Name', 'placeholder' => 'Your name', 'is_required' => true, 'sort_order' => 1],
                ['type' => 'email', 'name' => 'email', 'label' => 'Email', 'placeholder' => 'you@example.com', 'is_required' => true, 'sort_order' => 2],
                ['type' => 'textarea', 'name' => 'message', 'label' => 'Message', 'placeholder' => 'How can we help?', 'is_required' => true, 'sort_order' => 3],
            ],
        ];

        return Response::html($this->view->layout(
            $title,
            $this->view->renderFormEditorPage($values, $id, $this->csrf->inputName(), $this->csrf->token()),
            AdminNavigation::items(),
            $this->flash->pull(),
        ));
    }

    private function persist(Request $request, ?int $id): Response
    {
        if (!$this->auth->check()) {
            $this->flash->error('Please sign in to manage forms.');

            return Response::redirect('/admin/login');
        }

        if (!$this->csrf->verifyToken((string) $request->input($this->csrf->inputName(), ''))) {
            $this->flash->error('CSRF verification failed.');

            return Response::redirect('/admin/forms');
        }

        $values = [
            'name' => $this->sanitizer->text((string) $request->input('name', '')),
            'key' => $this->sanitizer->text((string) $request->input('key', '')),
            'description' => $this->sanitizer->text((string) $request->input('description', '')),
            'success_message' => $this->sanitizer->text((string) $request->input('success_message', '')),
            'send_email' => $request->input('send_email') === '1',
            'email_to' => trim((string) $request->input('email_to', '')),
            'save_submissions' => $request->input('save_submissions') === '1',
            'captcha_enabled' => $request->input('captcha_enabled') === '1',
            'is_active' => $request->input('is_active') === '1',
        ];

        $fields = [];
        $types = (array) $request->input('field_type', []);
        $names = (array) $request->input('field_name', []);
        $labels = (array) $request->input('field_label', []);
        $placeholders = (array) $request->input('field_placeholder', []);
        $requiredFlags = (array) $request->input('field_required', []);
        $sortOrders = (array) $request->input('field_sort_order', []);

        foreach ($labels as $index => $label) {
            $fields[] = [
                'type' => (string) ($types[$index] ?? 'text'),
                'name' => $this->sanitizer->text((string) ($names[$index] ?? '')),
                'label' => $this->sanitizer->text((string) $label),
                'placeholder' => $this->sanitizer->text((string) ($placeholders[$index] ?? '')),
                'is_required' => isset($requiredFlags[$index]) && $requiredFlags[$index] === '1',
                'sort_order' => $sortOrders[$index] ?? ($index + 1),
            ];
        }

        try {
            if ($id === null) {
                $createdId = $this->forms->create($values, $fields);
                $this->flash->success('Form created.');

                return Response::redirect('/admin/forms/' . $createdId . '/edit');
            }

            $this->forms->update($id, $values, $fields);
            $this->flash->success('Form updated.');
        } catch (RuntimeException $exception) {
            $this->flash->error($exception->getMessage());

            return Response::redirect($id === null ? '/admin/forms/create' : '/admin/forms/' . $id . '/edit');
        }

        return Response::redirect('/admin/forms/' . $id . '/edit');
    }
}
