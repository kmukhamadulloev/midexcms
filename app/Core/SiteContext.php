<?php

declare(strict_types=1);

namespace PlainCMS\Core;

final class SiteContext
{
    public function __construct(
        private readonly string $title,
        private readonly string $description,
        private readonly string $activeTheme,
        private readonly bool $commentsEnabled,
        private readonly bool $commentsModerationRequired,
        private readonly string $uploadsUrl,
        private readonly int $uploadsMaxSizeMb,
        private readonly string $cacheDriver,
    ) {
    }

    /**
     * @return array{
     *   site: array{title: string, description: string},
     *   theme: array{active: string},
     *   comments: array{enabled: bool, moderation_required: bool},
     *   uploads: array{url: string, max_size_mb: int},
     *   cache: array{driver: string}
     * }
     */
    public function toArray(): array
    {
        return [
            'site' => [
                'title' => $this->title,
                'description' => $this->description,
            ],
            'theme' => [
                'active' => $this->activeTheme,
            ],
            'comments' => [
                'enabled' => $this->commentsEnabled,
                'moderation_required' => $this->commentsModerationRequired,
            ],
            'uploads' => [
                'url' => $this->uploadsUrl,
                'max_size_mb' => $this->uploadsMaxSizeMb,
            ],
            'cache' => [
                'driver' => $this->cacheDriver,
            ],
        ];
    }
}
