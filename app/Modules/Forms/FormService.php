<?php

declare(strict_types=1);

namespace MidexCMS\Modules\Forms;

use MidexCMS\Core\Cache\CacheInterface;
use MidexCMS\Core\Support\Str;
use RuntimeException;

final class FormService
{
    public function __construct(
        private readonly FormRepository $forms,
        private readonly CacheInterface $cache
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForms(): array
    {
        return $this->forms->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findForm(int $id): ?array
    {
        $form = $this->forms->find($id);

        if ($form === null) {
            return null;
        }

        $form['fields'] = $this->normalizeLoadedFields($this->forms->fields($id));

        return $form;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function formByKey(string $key): ?array
    {
        $cacheKey = 'form:key:' . $key;
        $cached = $this->cache->get($cacheKey);

        if (is_array($cached)) {
            return $cached;
        }

        $form = $this->forms->findByKey($key);

        if ($form === null) {
            return null;
        }

        $form['fields'] = $this->normalizeLoadedFields($this->forms->fields((int) $form['id']));
        $this->cache->put($cacheKey, $form, 3600);

        return $form;
    }

    public function create(array $values, array $fields): int
    {
        $payload = $this->normalizeFormPayload($values, null);
        $normalizedFields = $this->normalizeFields($fields);
        $id = $this->forms->create($payload);
        $this->forms->replaceFields($id, $normalizedFields);
        $this->invalidate();

        return $id;
    }

    public function update(int $id, array $values, array $fields): void
    {
        $this->requireForm($id);
        $payload = $this->normalizeFormPayload($values, $id);
        $normalizedFields = $this->normalizeFields($fields);
        $this->forms->update($id, $payload);
        $this->forms->replaceFields($id, $normalizedFields);
        $this->invalidate();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function submissions(int $formId): array
    {
        $this->requireForm($formId);
        $rows = $this->forms->submissions($formId);

        foreach ($rows as &$row) {
            $row['payload'] = is_array($row['payload']) ? $row['payload'] : json_decode((string) $row['payload'], true);
        }

        return $rows;
    }

    public function submit(string $key, array $input, ?int $pageId, string $ipAddress, string $userAgent): array
    {
        $form = $this->formByKey($key);

        if ($form === null) {
            throw new RuntimeException('Form not found.');
        }

        $fields = $form['fields'] ?? [];
        $payload = [];

        foreach ($fields as $field) {
            $name = (string) $field['name'];
            $value = trim((string) ($input[$name] ?? ''));

            if ((bool) $field['is_required'] && $value === '') {
                throw new RuntimeException(sprintf('The %s field is required.', $name));
            }

            if ($field['type'] === 'email' && $value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                throw new RuntimeException(sprintf('The %s field must be a valid email address.', $name));
            }

            $payload[$name] = $value;
        }

        if ((bool) $form['save_submissions']) {
            $this->forms->createSubmission([
                'form_id' => (int) $form['id'],
                'page_id' => $pageId,
                'payload' => $payload,
                'ip_hash' => sha1($ipAddress),
                'user_agent_hash' => sha1($userAgent),
            ]);
        }

        if ((bool) $form['send_email'] && is_string($form['email_to']) && trim($form['email_to']) !== '') {
            $subject = 'New form submission: ' . (string) $form['name'];
            $body = '';

            foreach ($payload as $name => $value) {
                $body .= $name . ': ' . $value . "\n";
            }

            @mail((string) $form['email_to'], $subject, trim($body));
        }

        return [
            'success_message' => (string) ($form['success_message'] ?? 'Thanks, your submission has been received.'),
        ];
    }

    /**
     * @param array<string, mixed> $form
     */
    public function renderPublicForm(array $form, ?int $pageId, string $action, string $csrfInput, string $csrfToken): string
    {
        $description = trim((string) ($form['description'] ?? ''));
        $descriptionHtml = $description !== ''
            ? '<p class="module-copy">' . htmlspecialchars($description, ENT_QUOTES, 'UTF-8') . '</p>'
            : '';
        $fieldsHtml = '';

        foreach (($form['fields'] ?? []) as $field) {
            $name = htmlspecialchars((string) $field['name'], ENT_QUOTES, 'UTF-8');
            $label = htmlspecialchars((string) $field['label'], ENT_QUOTES, 'UTF-8');
            $placeholder = htmlspecialchars((string) ($field['placeholder'] ?? ''), ENT_QUOTES, 'UTF-8');
            $required = (bool) ($field['is_required'] ?? false) ? ' required' : '';

            if (($field['type'] ?? 'text') === 'textarea') {
                $fieldsHtml .= '<div class="public-form-row"><label for="form-' . $name . '">' . $label . '</label><textarea id="form-' . $name . '" name="' . $name . '" placeholder="' . $placeholder . '"' . $required . '></textarea></div>';
                continue;
            }

            $type = in_array((string) $field['type'], ['text', 'email'], true) ? (string) $field['type'] : 'text';
            $fieldsHtml .= '<div class="public-form-row"><label for="form-' . $name . '">' . $label . '</label><input id="form-' . $name . '" type="' . htmlspecialchars($type, ENT_QUOTES, 'UTF-8') . '" name="' . $name . '" placeholder="' . $placeholder . '"' . $required . '></div>';
        }

        return '<section class="module-card public-form-card"><div class="module-head"><p class="module-kicker">Contact</p><h2 class="module-title">' . htmlspecialchars((string) ($form['name'] ?? 'Form'), ENT_QUOTES, 'UTF-8') . '</h2>' . $descriptionHtml . '</div><form method="post" action="' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8') . '" class="public-form">'
            . '<input type="hidden" name="' . htmlspecialchars($csrfInput, ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') . '">'
            . '<input type="hidden" name="page_id" value="' . htmlspecialchars((string) ($pageId ?? ''), ENT_QUOTES, 'UTF-8') . '">'
            . $fieldsHtml
            . '<div class="public-form-actions"><button type="submit">Send message</button></div></form></section>';
    }

    /**
     * @param array<string, mixed> $values
     */
    private function normalizeFormPayload(array $values, ?int $formId): array
    {
        $name = trim((string) ($values['name'] ?? ''));
        $keyInput = trim((string) ($values['key'] ?? ''));
        $key = $keyInput !== '' ? Str::slug($keyInput) : Str::slug($name);

        if ($name === '' || $key === '') {
            throw new RuntimeException('Form name and key are required.');
        }

        if ($this->forms->keyExists($key, $formId)) {
            throw new RuntimeException('Form key must be unique.');
        }

        return [
            'key' => $key,
            'name' => $name,
            'description' => $this->nullable($values['description'] ?? null),
            'success_message' => $this->nullable($values['success_message'] ?? null),
            'send_email' => $this->truthy($values['send_email'] ?? false),
            'email_to' => $this->nullable($values['email_to'] ?? null),
            'save_submissions' => $this->truthy($values['save_submissions'] ?? true),
            'captcha_enabled' => $this->truthy($values['captcha_enabled'] ?? false),
            'is_active' => $this->truthy($values['is_active'] ?? true),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $fields
     * @return array<int, array<string, mixed>>
     */
    private function normalizeFields(array $fields): array
    {
        $normalized = [];

        foreach ($fields as $index => $field) {
            $type = (string) ($field['type'] ?? 'text');
            $name = strtolower(trim((string) ($field['name'] ?? '')));
            $name = preg_replace('/[^a-z0-9_]+/i', '_', $name) ?? '';
            $name = trim($name, '_');
            $label = trim((string) ($field['label'] ?? ''));

            if ($name === '' || $label === '') {
                continue;
            }

            if (!in_array($type, ['text', 'email', 'textarea'], true)) {
                $type = 'text';
            }

            $normalized[] = [
                'type' => $type,
                'name' => $name,
                'label' => $label,
                'placeholder' => $this->nullable($field['placeholder'] ?? null),
                'options' => [],
                'is_required' => $this->truthy($field['is_required'] ?? false),
                'sort_order' => is_numeric((string) ($field['sort_order'] ?? '')) ? (int) $field['sort_order'] : ($index + 1),
            ];
        }

        if ($normalized === []) {
            throw new RuntimeException('At least one form field is required.');
        }

        return $normalized;
    }

    /**
     * @param array<int, array<string, mixed>> $fields
     * @return array<int, array<string, mixed>>
     */
    private function normalizeLoadedFields(array $fields): array
    {
        foreach ($fields as &$field) {
            $field['options'] = is_array($field['options']) ? $field['options'] : json_decode((string) ($field['options'] ?? '[]'), true);
        }

        return $fields;
    }

    /**
     * @return array<string, mixed>
     */
    private function requireForm(int $id): array
    {
        $form = $this->findForm($id);

        if ($form === null) {
            throw new RuntimeException('Form not found.');
        }

        return $form;
    }

    private function truthy(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1';
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function invalidate(): void
    {
        $this->cache->flush();
    }
}
