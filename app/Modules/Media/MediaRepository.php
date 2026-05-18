<?php

declare(strict_types=1);

namespace MidexCMS\Modules\Media;

use MidexCMS\Core\Database;

final class MediaRepository
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
            'SELECT id, path, url, filename, original_filename, mime_type, size_bytes, width, height, alt, created_at
             FROM media
             ORDER BY id DESC'
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->database->selectOne(
            'SELECT * FROM media WHERE id = :id LIMIT 1',
            ['id' => $id]
        );
    }

    public function create(array $payload): int
    {
        $this->database->statement(
            'INSERT INTO media (disk, path, url, filename, original_filename, mime_type, size_bytes, width, height, alt)
             VALUES (:disk, :path, :url, :filename, :original_filename, :mime_type, :size_bytes, :width, :height, :alt)',
            $payload
        );

        $row = $this->database->selectOne('SELECT currval(pg_get_serial_sequence(\'media\', \'id\')) AS id');

        return (int) ($row['id'] ?? 0);
    }

    public function updateAlt(int $id, ?string $alt): void
    {
        $this->database->statement(
            'UPDATE media SET alt = :alt, updated_at = CURRENT_TIMESTAMP WHERE id = :id',
            ['id' => $id, 'alt' => $alt]
        );
    }

    public function delete(int $id): void
    {
        $this->database->statement('DELETE FROM media WHERE id = :id', ['id' => $id]);
    }
}
