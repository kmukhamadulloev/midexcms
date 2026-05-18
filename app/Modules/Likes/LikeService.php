<?php

declare(strict_types=1);

namespace MidexCMS\Modules\Likes;

use MidexCMS\Core\Cache\CacheInterface;

final class LikeService
{
    public function __construct(
        private readonly LikeRepository $likes,
        private readonly CacheInterface $cache
    ) {
    }

    public function toggle(int $pageId, string $visitorHash): array
    {
        $existing = $this->likes->find($pageId, $visitorHash);

        if ($existing === null) {
            $this->likes->create($pageId, $visitorHash);
            $liked = true;
        } else {
            $this->likes->delete($pageId, $visitorHash);
            $liked = false;
        }

        $count = $this->likes->count($pageId);
        $this->likes->updatePageCount($pageId, $count);
        $this->cache->flush();

        return ['liked' => $liked, 'count' => $count];
    }

    public function liked(int $pageId, string $visitorHash): bool
    {
        return $this->likes->find($pageId, $visitorHash) !== null;
    }

    public function renderForPage(array $page, string $action, string $csrfInput, string $csrfToken, string $visitorHash): string
    {
        $count = (int) ($page['likes_count'] ?? 0);
        $liked = $this->liked((int) $page['id'], $visitorHash);
        $label = $liked ? 'Unlike' : 'Like';

        return '<section class="module-card likes-shell"><div class="module-head"><p class="module-kicker">Appreciation</p><h2 class="module-title">Enjoyed this page?</h2><p class="module-copy">Save a quick vote without leaving the page.</p></div><button type="button" class="like-button' . ($liked ? ' is-liked' : '') . '" data-like-toggle data-endpoint="' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8') . '" data-csrf-input="' . htmlspecialchars($csrfInput, ENT_QUOTES, 'UTF-8') . '" data-csrf-token="' . htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') . '"><span data-like-label>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span> <span class="like-divider" aria-hidden="true">·</span> <span><span data-like-count>' . $count . '</span> likes</span></button></section>';
    }

    public function visitorHash(string $ipAddress, string $userAgent): string
    {
        return sha1($ipAddress . '|' . $userAgent);
    }
}
