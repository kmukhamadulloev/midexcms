<?php

declare(strict_types=1);

namespace PlainCMS\Modules\Media;

use PlainCMS\Core\Cache\CacheInterface;
use RuntimeException;

final class MediaService
{
    /**
     * @param array<string, string> $allowedMimeTypes
     * @param array<int, string> $blockedExtensions
     */
    public function __construct(
        private readonly MediaRepository $media,
        private readonly CacheInterface $cache,
        private readonly string $uploadsPath,
        private readonly string $uploadsUrl,
        private readonly int $maxSizeMb,
        private readonly array $allowedMimeTypes = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
        ],
        private readonly array $blockedExtensions = ['php', 'phtml', 'phar', 'js', 'html', 'htm', 'exe', 'sh', 'bat', 'cmd']
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listMedia(): array
    {
        return $this->media->all();
    }

    public function upload(array $file, ?string $alt = null): int
    {
        $this->validateUpload($file);

        $tmpPath = (string) $file['tmp_name'];
        $originalName = (string) $file['name'];
        $size = (int) $file['size'];
        $mimeType = $this->detectMimeType($tmpPath);
        $extension = $this->allowedMimeTypes[$mimeType] ?? null;

        if ($extension === null) {
            throw new RuntimeException('Uploaded file type is not allowed.');
        }

        $yearMonth = date('Y/m');
        $directory = rtrim($this->uploadsPath, '/') . '/' . $yearMonth;

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create uploads directory.');
        }

        $filename = $this->safeFilename(pathinfo($originalName, PATHINFO_FILENAME)) . '-' . bin2hex(random_bytes(8)) . '.' . $extension;
        $targetPath = $directory . '/' . $filename;

        if (!move_uploaded_file($tmpPath, $targetPath) && !rename($tmpPath, $targetPath)) {
            throw new RuntimeException('Unable to move uploaded file.');
        }

        [$width, $height] = $this->imageSize($targetPath);
        $relativePath = $yearMonth . '/' . $filename;
        $url = rtrim($this->uploadsUrl, '/') . '/' . $relativePath;

        $id = $this->media->create([
            'disk' => 'local',
            'path' => $relativePath,
            'url' => $url,
            'filename' => $filename,
            'original_filename' => $originalName,
            'mime_type' => $mimeType,
            'size_bytes' => $size,
            'width' => $width,
            'height' => $height,
            'alt' => $this->nullable($alt),
        ]);

        $this->cache->flush();

        return $id;
    }

    public function updateAlt(int $id, ?string $alt): void
    {
        $record = $this->requireMedia($id);
        $this->media->updateAlt((int) $record['id'], $this->nullable($alt));
        $this->cache->flush();
    }

    public function delete(int $id): void
    {
        $record = $this->requireMedia($id);
        $path = rtrim($this->uploadsPath, '/') . '/' . ltrim((string) $record['path'], '/');

        if (is_file($path)) {
            unlink($path);
        }

        $this->media->delete($id);
        $this->cache->flush();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->media->find($id);
    }

    public function resolveUploadPath(string $relativePath): string
    {
        if (str_contains($relativePath, '..')) {
            throw new RuntimeException('Invalid upload path.');
        }

        $path = rtrim($this->uploadsPath, '/') . '/' . ltrim($relativePath, '/');
        $realBase = realpath($this->uploadsPath);
        $realPath = realpath($path);

        if ($realBase === false || $realPath === false || ($realPath !== $realBase && !str_starts_with($realPath, $realBase . '/'))) {
            throw new RuntimeException('Upload path resolves outside uploads directory.');
        }

        return $realPath;
    }

    private function validateUpload(array $file): void
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload failed.');
        }

        if (!isset($file['tmp_name'], $file['name'], $file['size'])) {
            throw new RuntimeException('Uploaded file payload is incomplete.');
        }

        $size = (int) $file['size'];

        if ($size < 1) {
            throw new RuntimeException('Uploaded file is empty.');
        }

        if ($size > $this->maxSizeMb * 1024 * 1024) {
            throw new RuntimeException(sprintf('Uploaded file exceeds the %d MB limit.', $this->maxSizeMb));
        }

        $extension = strtolower((string) pathinfo((string) $file['name'], PATHINFO_EXTENSION));

        if ($extension !== '' && in_array($extension, $this->blockedExtensions, true)) {
            throw new RuntimeException('That file extension is not allowed.');
        }
    }

    private function detectMimeType(string $path): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = $finfo !== false ? finfo_file($finfo, $path) : false;

        if ($finfo !== false) {
            finfo_close($finfo);
        }

        if (!is_string($mimeType) || $mimeType === '') {
            throw new RuntimeException('Unable to detect uploaded file type.');
        }

        return $mimeType;
    }

    /**
     * @return array{0: int|null, 1: int|null}
     */
    private function imageSize(string $path): array
    {
        $info = @getimagesize($path);

        if (!is_array($info)) {
            return [null, null];
        }

        return [$info[0] ?? null, $info[1] ?? null];
    }

    /**
     * @return array<string, mixed>
     */
    private function requireMedia(int $id): array
    {
        $record = $this->media->find($id);

        if ($record === null) {
            throw new RuntimeException('Media item not found.');
        }

        return $record;
    }

    private function safeFilename(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/i', '-', $value) ?? '';
        $value = trim($value, '-');

        return $value !== '' ? $value : 'upload';
    }

    private function nullable(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
