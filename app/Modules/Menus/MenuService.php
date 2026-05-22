<?php

declare(strict_types=1);

namespace MidexCMS\Modules\Menus;

use MidexCMS\Core\Cache\CacheInterface;
use MidexCMS\Core\Theme\ThemeLoader;
use MidexCMS\Core\Support\Str;
use RuntimeException;

final class MenuService
{
    public function __construct(
        private readonly MenuRepository $menus,
        private readonly MenuPlacementRepository $placements,
        private readonly ThemeLoader $themes,
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

    /**
     * @return array<int, array{key: string, label: string, allowed_components: array<int, string>, multiple: bool, description: string}>
     */
    public function menuSlotsForTheme(string $themeKey): array
    {
        $theme = $this->themes->load($themeKey);
        $slots = $theme['module_slots'] ?? [];

        if (!is_array($slots)) {
            return [];
        }

        return array_values(array_filter($slots, static function (mixed $slot): bool {
            return is_array($slot) && in_array('menu', (array) ($slot['allowed_components'] ?? []), true);
        }));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function placementsForTheme(string $themeKey): array
    {
        $placements = [];

        foreach ($this->placements->placementsForTheme($themeKey) as $placement) {
            $placements[(string) $placement['slot_key']] = $placement;
        }

        return $placements;
    }

    public function assignMenuToSlot(string $themeKey, string $slotKey, ?int $menuId): void
    {
        $slot = $this->slotDefinition($themeKey, $slotKey);

        if (!in_array('menu', $slot['allowed_components'], true)) {
            throw new RuntimeException('This template slot does not accept menu components.');
        }

        if ($slot['multiple']) {
            throw new RuntimeException('Multi-placement slots are not supported in this version.');
        }

        if ($menuId === null) {
            $this->placements->deletePlacement($themeKey, $slotKey);
            $this->cache->flush();

            return;
        }

        if ($this->menus->findMenu($menuId) === null) {
            throw new RuntimeException('Selected menu was not found.');
        }

        $this->placements->savePlacement($themeKey, $slotKey, 'menu', $menuId, 1);
        $this->cache->flush();
    }

    /**
     * @return array<string, string>
     */
    public function renderSlotsForTheme(string $themeKey): array
    {
        $slots = [];

        foreach ($this->menuSlotsForTheme($themeKey) as $slot) {
            $slots[(string) $slot['key']] = '';
        }

        foreach ($this->placements->placementsForTheme($themeKey) as $placement) {
            if ((string) ($placement['component_type'] ?? '') !== 'menu') {
                continue;
            }

            $slotKey = (string) ($placement['slot_key'] ?? '');
            $menuId = (int) ($placement['component_ref_id'] ?? 0);

            if ($slotKey === '' || $menuId <= 0 || !array_key_exists($slotKey, $slots)) {
                continue;
            }

            $slots[$slotKey] = $this->renderMenuById($menuId);
        }

        if ($themeKey === 'cyberpunk' && ($slots['header_nav'] ?? '') === '') {
            $slots['header_nav'] = $this->renderMenu('main');
        }

        return $slots;
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

    public function renderMenuById(int $id): string
    {
        $cacheKey = 'menu_html:id:' . $id;
        $cached = $this->cache->get($cacheKey);

        if (is_string($cached)) {
            return $cached;
        }

        $menu = $this->findMenu($id);

        if ($menu === null) {
            return '';
        }

        $tree = $this->tree((array) ($menu['items'] ?? []));
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
        $knownIds = [];

        foreach ($items as $item) {
            $label = trim((string) ($item['label'] ?? ''));
            $url = trim((string) ($item['url'] ?? ''));
            $linkType = trim((string) ($item['link_type'] ?? 'page'));
            $pageId = isset($item['page_id']) && ctype_digit((string) $item['page_id']) ? (int) $item['page_id'] : null;
            $parentRef = isset($item['parent_ref']) && $item['parent_ref'] !== '' ? (string) $item['parent_ref'] : null;
            $clientId = trim((string) ($item['client_id'] ?? ''));

            if ($label === '' && $url === '' && $pageId === null) {
                continue;
            }

            if ($clientId === '') {
                throw new RuntimeException('Menu item state is invalid.');
            }

            if (!in_array($linkType, ['page', 'custom_url'], true)) {
                $linkType = $pageId !== null ? 'page' : 'custom_url';
            }

            if ($label === '') {
                throw new RuntimeException('Menu item label is required.');
            }

            if ($linkType === 'page' && $pageId === null) {
                throw new RuntimeException(sprintf('Menu item "%s" must select a page.', $label));
            }

            if ($linkType === 'custom_url' && $url === '') {
                throw new RuntimeException(sprintf('Menu item "%s" must link to a page or a custom URL.', $label));
            }

            if ($linkType === 'page') {
                $url = '';
            } else {
                $pageId = null;
            }

            if ($parentRef === $clientId) {
                throw new RuntimeException(sprintf('Menu item "%s" cannot be its own parent.', $label));
            }

            $normalized[] = [
                'client_id' => $clientId,
                'parent_ref' => $parentRef !== '' ? $parentRef : null,
                'page_id' => $pageId,
                'label' => $label,
                'url' => $url === '' ? null : $url,
                'target' => in_array(($item['target'] ?? '_self'), ['_self', '_blank'], true) ? $item['target'] : '_self',
                'sort_order' => count($normalized) + 1,
            ];

            $knownIds[] = $clientId;
        }

        foreach ($normalized as $item) {
            if ($item['parent_ref'] !== null && !in_array($item['parent_ref'], $knownIds, true)) {
                throw new RuntimeException(sprintf('Parent item for "%s" is invalid.', $item['label']));
            }
        }

        $parentsById = [];

        foreach ($normalized as $item) {
            $parentsById[$item['client_id']] = $item['parent_ref'];
        }

        foreach (array_keys($parentsById) as $clientId) {
            $visited = [];
            $cursor = $clientId;

            while ($parentsById[$cursor] ?? null) {
                $parent = (string) $parentsById[$cursor];

                if (isset($visited[$parent])) {
                    throw new RuntimeException('Menu hierarchy cannot contain circular relationships.');
                }

                $visited[$parent] = true;
                $cursor = $parent;
            }
        }

        return $normalized;
    }

    /**
     * @return array{key: string, label: string, allowed_components: array<int, string>, multiple: bool, description: string}
     */
    private function slotDefinition(string $themeKey, string $slotKey): array
    {
        foreach ($this->menuSlotsForTheme($themeKey) as $slot) {
            if ((string) $slot['key'] === $slotKey) {
                return $slot;
            }
        }

        throw new RuntimeException('Template slot was not found for the active theme.');
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
