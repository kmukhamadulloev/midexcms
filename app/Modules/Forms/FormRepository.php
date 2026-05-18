<?php

declare(strict_types=1);

namespace MidexCMS\Modules\Forms;

use MidexCMS\Core\Database;

final class FormRepository
{
    public function __construct(
        private readonly Database $database
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return $this->database->select(
            'SELECT id, key, name, description, success_message, send_email, email_to, save_submissions, captcha_enabled, is_active, created_at, updated_at
             FROM forms
             ORDER BY name ASC'
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->database->selectOne('SELECT * FROM forms WHERE id = :id LIMIT 1', ['id' => $id]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByKey(string $key): ?array
    {
        return $this->database->selectOne(
            'SELECT * FROM forms WHERE key = :key AND is_active = TRUE LIMIT 1',
            ['key' => $key]
        );
    }

    public function create(array $payload): int
    {
        $this->database->statement(
            'INSERT INTO forms (key, name, description, success_message, send_email, email_to, save_submissions, captcha_enabled, is_active)
             VALUES (:key, :name, :description, :success_message, :send_email, :email_to, :save_submissions, :captcha_enabled, :is_active)',
            $payload
        );

        $row = $this->database->selectOne('SELECT currval(pg_get_serial_sequence(\'forms\', \'id\')) AS id');

        return (int) ($row['id'] ?? 0);
    }

    public function update(int $id, array $payload): void
    {
        $payload['id'] = $id;
        $this->database->statement(
            'UPDATE forms SET
                key = :key,
                name = :name,
                description = :description,
                success_message = :success_message,
                send_email = :send_email,
                email_to = :email_to,
                save_submissions = :save_submissions,
                captcha_enabled = :captcha_enabled,
                is_active = :is_active,
                updated_at = CURRENT_TIMESTAMP
             WHERE id = :id',
            $payload
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fields(int $formId): array
    {
        return $this->database->select(
            'SELECT id, form_id, type, name, label, placeholder, options, is_required, sort_order
             FROM form_fields
             WHERE form_id = :form_id
             ORDER BY sort_order ASC, id ASC',
            ['form_id' => $formId]
        );
    }

    public function replaceFields(int $formId, array $fields): void
    {
        $this->database->transaction(function (Database $database) use ($formId, $fields): void {
            $database->statement('DELETE FROM form_fields WHERE form_id = :form_id', ['form_id' => $formId]);

            foreach ($fields as $field) {
                $database->statement(
                    'INSERT INTO form_fields (form_id, type, name, label, placeholder, options, is_required, sort_order)
                     VALUES (:form_id, :type, :name, :label, :placeholder, CAST(:options AS JSONB), :is_required, :sort_order)',
                    [
                        'form_id' => $formId,
                        'type' => $field['type'],
                        'name' => $field['name'],
                        'label' => $field['label'],
                        'placeholder' => $field['placeholder'],
                        'options' => json_encode($field['options'], JSON_THROW_ON_ERROR),
                        'is_required' => $field['is_required'],
                        'sort_order' => $field['sort_order'],
                    ]
                );
            }
        });
    }

    public function createSubmission(array $payload): int
    {
        $this->database->statement(
            'INSERT INTO form_submissions (form_id, page_id, payload, ip_hash, user_agent_hash)
             VALUES (:form_id, :page_id, CAST(:payload AS JSONB), :ip_hash, :user_agent_hash)',
            [
                'form_id' => $payload['form_id'],
                'page_id' => $payload['page_id'],
                'payload' => json_encode($payload['payload'], JSON_THROW_ON_ERROR),
                'ip_hash' => $payload['ip_hash'],
                'user_agent_hash' => $payload['user_agent_hash'],
            ]
        );

        $row = $this->database->selectOne('SELECT currval(pg_get_serial_sequence(\'form_submissions\', \'id\')) AS id');

        return (int) ($row['id'] ?? 0);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function submissions(int $formId): array
    {
        return $this->database->select(
            'SELECT id, form_id, page_id, payload, created_at
             FROM form_submissions
             WHERE form_id = :form_id
             ORDER BY id DESC',
            ['form_id' => $formId]
        );
    }

    public function keyExists(string $key, ?int $excludeId = null): bool
    {
        $sql = 'SELECT id FROM forms WHERE key = :key';
        $params = ['key' => $key];

        if ($excludeId !== null) {
            $sql .= ' AND id != :exclude_id';
            $params['exclude_id'] = $excludeId;
        }

        return $this->database->selectOne($sql . ' LIMIT 1', $params) !== null;
    }
}
