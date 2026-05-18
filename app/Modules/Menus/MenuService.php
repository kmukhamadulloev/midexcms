<?php

declare(strict_types=1);

namespace MidexCMS\Modules\Menus;

use MidexCMS\Core\Cache\CacheInterface;
use MidexCMS\Core\Support\Str;
use RuntimeException;

final class MenuService
{
    public function __construct(
        private readonly MenuRepository $menus,
        private readonly CacheInterface $cache
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listMenus(): array
    {
        return $this->menus->allMenus();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findMenu(int $id): ?array
    {
        $menu = $this->menus->findMenu($id);

        if ($menu === null) {
            return null;
        }

        $menu['items'] = $this->menus->itemsForMenu($id);

        return $menu;
    }

    public function createMenu(string $name, ?string $key = null): int
    {
        $name = trim($name);

        if ($name === '') {
            throw new RuntimeException('Menu name is required.');
        }

        $menuKey = $key !== null && trim($key) !== '' ? Str::slug($key) : Str::slug($name);

        if ($menuKey === '') {
            throw new RuntimeException('Menu key could not be generated.');
        }

        $id = $this->menus->createMenu($menuKey, $name);
        $this->cache->flush();

        return $id;
    }

    public function updateMenu(int $id, string $name, array $items): void
    {
        $menu = $this->menus->findMenu($id);

        if ($menu === null) {
            throw new RuntimeException('Menu not found.');
        }

        $name = trim($name);

        if ($name === '') {
            throw new RuntimeException('Menu name is required.');
        }

        $normalizedItems = $this->normalizeItems($items);
        $this->menus->updateMenu($id, $name);
        $this->menus->replaceItems($id, $normalizedItems);
        $this->cache->flush();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function pageOptions(): array
    {
        return $this->menus->pageOptions();
    }

    public function renderMenu(string $key): string
    {
        $cacheKey = 'menu_html:' . $key;
        $cached = $this->cache->get($cacheKey);

        if (is_string($cached)) {
            return $cached;
        }

        $menu = $this->menus->menuByKey($key);

        if ($menu === null) {
            return '';
        }

        $items = $this->menus->itemsForMenu((int) $menu['id']);
        $tree = $this->tree($items);
        $html = $tree === [] ? '' : '<ul class="menu-list">' . $this->renderTree($tree) . '</ul>';
        $this->cache->put($cacheKey, $html, 3600);

        return $html;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function normalizeItems(array $items): array
    {
        $normalized = [];

        foreach ($items as $item) {
            $label = trim((string) ($item['label'] ?? ''));
            $url = trim((string) ($item['url'] ?? ''));
            $pageId = isset($item['page_id']) && $item['page_id'] !== '' ? (int) $item['page_id'] : null;
            $parentRef = isset($item['parent_ref']) && $item['parent_ref'] !== '' ? (int) $item['parent_ref'] : null;

            if ($label === '') {
                continue;
            }

            if ($pageId === null && $url === '') {
                throw new RuntimeException(sprintf('Menu item "%s" must link to a page or a custom URL.', $label));
            }

            $normalized[] = [
                'parent_ref' => $parentRef,
                'row_index' => isset($item['row_index']) ? (int) $item['row_index'] : count($normalized) + 1,
                'page_id' => $pageId,
                'label' => $label,
                'url' => $url === '' ? null : $url,
                'target' => in_array(($item['target'] ?? '_self'), ['_self', '_blank'], true) ? $item['target'] : '_self',
                'sort_order' => isset($item['sort_order']) && is_numeric((string) $item['sort_order']) ? (int) $item['sort_order'] : 0,
            ];
        }

        return $normalized;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function tree(array $items, ?int $parentId = null): array
    {
        $branch = [];

        foreach ($items as $item) {
            $itemParent = $item['parent_id'] !== null ? (int) $item['parent_id'] : null;

            if ($itemParent !== $parentId) {
                continue;
            }

            $item['children'] = $this->tree($items, (int) $item['id']);
            $branch[] = $item;
        }

        return $branch;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    private function renderTree(array $items): string
    {
        $html = '';

        foreach ($items as $item) {
            $href = (string) (($item['resolved_url'] ?? $item['url']) ?: '#');
            $target = (string) ($item['target'] ?? '_self');
            $children = $item['children'] ?? [];
            $html .= '<li class="menu-item"><a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" target="' . htmlspecialchars($target, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars((string) $item['label'], ENT_QUOTES, 'UTF-8') . '</a>';

            if (is_array($children) && $children !== []) {
                $html .= '<ul class="menu-list menu-sublist">' . $this->renderTree($children) . '</ul>';
            }

            $html .= '</li>';
        }

        return $html;
    }
}
