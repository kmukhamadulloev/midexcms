<?php

declare(strict_types=1);

namespace MidexCMS\Modules\Backups;

use MidexCMS\Core\Cache\CacheInterface;
use MidexCMS\Core\Database;
use RuntimeException;
use ZipArchive;

final class BackupService
{
    private const TABLES = [
        'users',
        'password_resets',
        'settings',
        'pages',
        'page_revisions',
        'media',
        'menus',
        'menu_items',
        'template_module_placements',
        'forms',
        'form_fields',
        'form_submissions',
        'comments',
        'likes',
        'page_views',
        'page_view_daily_stats',
        'rate_limits',
    ];

    public function __construct(
        private readonly Database $database,
        private readonly CacheInterface $cache,
        private readonly string $backupsPath,
        private readonly string $uploadsPath
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listBackups(): array
    {
        $entries = [];

        foreach (glob(rtrim($this->backupsPath, '/') . '/*', GLOB_ONLYDIR) ?: [] as $directory) {
            $name = basename($directory);
            $files = [];

            foreach (glob($directory . '/*') ?: [] as $file) {
                $files[] = [
                    'name' => basename($file),
                    'size' => is_file($file) ? (int) filesize($file) : 0,
                ];
            }

            usort($files, static fn (array $left, array $right): int => strcmp($left['name'], $right['name']));
            $entries[] = ['name' => $name, 'files' => $files];
        }

        usort($entries, static fn (array $left, array $right): int => strcmp($right['name'], $left['name']));

        return $entries;
    }

    /**
     * @return array{name: string, files: array<int, string>}
     */
    public function create(): array
    {
        $name = date('Y-m-d_H-i-s');
        $directory = rtrim($this->backupsPath, '/') . '/' . $name;

        if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create backup directory.');
        }

        $databaseDump = [];

        foreach (self::TABLES as $table) {
            $databaseDump[$table] = $this->database->select('SELECT * FROM ' . $table);
        }

        file_put_contents($directory . '/database.json', json_encode($databaseDump, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        file_put_contents($directory . '/manifest.json', json_encode([
            'created_at' => date(DATE_ATOM),
            'tables' => self::TABLES,
            'uploads_included' => is_dir($this->uploadsPath),
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        $uploadsTarget = $directory . '/uploads';

        if (is_dir($this->uploadsPath)) {
            $this->copyDirectory($this->uploadsPath, $uploadsTarget);
        }

        $files = ['database.json', 'manifest.json'];

        if (class_exists(ZipArchive::class)) {
            $zip = new ZipArchive();
            $zipPath = $directory . '/full-backup.zip';

            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
                $this->zipDirectory($zip, $directory, $directory);
                $zip->close();
                $files[] = 'full-backup.zip';
            }
        }

        $this->cache->flush();

        return ['name' => $name, 'files' => $files];
    }

    public function resolveDownload(string $backupName, string $fileName): string
    {
        if (str_contains($backupName, '..') || str_contains($fileName, '..')) {
            throw new RuntimeException('Invalid backup path.');
        }

        $path = rtrim($this->backupsPath, '/') . '/' . $backupName . '/' . ltrim($fileName, '/');
        $realBase = realpath(rtrim($this->backupsPath, '/'));
        $realPath = realpath($path);

        if ($realBase === false || $realPath === false || ($realPath !== $realBase && !str_starts_with($realPath, $realBase . '/'))) {
            throw new RuntimeException('Backup path resolves outside the backups directory.');
        }

        return $realPath;
    }

    private function copyDirectory(string $source, string $destination): void
    {
        if (!is_dir($destination) && !mkdir($destination, 0775, true) && !is_dir($destination)) {
            throw new RuntimeException('Unable to create backup uploads directory.');
        }

        foreach (scandir($source) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $sourcePath = $source . '/' . $item;
            $destinationPath = $destination . '/' . $item;

            if (is_dir($sourcePath)) {
                $this->copyDirectory($sourcePath, $destinationPath);
                continue;
            }

            copy($sourcePath, $destinationPath);
        }
    }

    private function zipDirectory(ZipArchive $zip, string $path, string $basePath): void
    {
        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $absolute = $path . '/' . $item;

            if ($absolute === $basePath . '/full-backup.zip') {
                continue;
            }

            $relative = ltrim(substr($absolute, strlen($basePath)), '/');

            if (is_dir($absolute)) {
                $zip->addEmptyDir($relative);
                $this->zipDirectory($zip, $absolute, $basePath);
                continue;
            }

            $zip->addFile($absolute, $relative);
        }
    }
}
