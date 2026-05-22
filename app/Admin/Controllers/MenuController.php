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
use MidexCMS\Modules\Menus\MenuService;
use RuntimeException;

final class MenuController
{
    public function __construct(
        private readonly Auth $auth,
        private readonly Csrf $csrf,
        private readonly Flash $flash,
        private readonly Sanitizer $sanitizer,
        private readonly MenuService $menus,
        private readonly AdminView $view,
    ) {
    }

    public function index(Request $request, array $parameters = []): Response
    {
        if (!$this->auth->check()) {
            $this->flash->error('Please sign in to manage menus.');

            return Response::redirect('/admin/login');
        }

        $content = $this->view->renderMenusIndexPage(
            $this->menus->listMenus(),
            $this->csrf->inputName(),
            $this->csrf->token(),
        );

        return Response::html($this->view->layout('Menus', $content, AdminNavigation::items(), $this->flash->pull()));
    }

    public function store(Request $request, array $parameters = []): Response
    {
        if (!$this->auth->check()) {
            $this->flash->error('Please sign in to manage menus.');

            return Response::redirect('/admin/login');
        }

        if (!$this->csrf->verifyToken((string) $request->input($this->csrf->inputName(), ''))) {
            $this->flash->error('CSRF verification failed.');

            return Response::redirect('/admin/menus');
        }

        try {
            $id = $this->menus->createMenu(
                $this->sanitizer->text((string) $request->input('name', '')),
                $this->sanitizer->text((string) $request->input('key', '')),
            );
            $this->flash->success('Menu created.');

            return Response::redirect('/admin/menus/' . $id . '/edit');
        } catch (RuntimeException $exception) {
            $this->flash->error($exception->getMessage());

            return Response::redirect('/admin/menus');
        }
    }

    public function edit(Request $request, array $parameters = []): Response
    {
        if (!$this->auth->check()) {
            $this->flash->error('Please sign in to manage menus.');

            return Response::redirect('/admin/login');
        }

        $menuId = (int) ($parameters['id'] ?? 0);
        $menu = $this->menus->findMenu($menuId);

        if ($menu === null) {
            $this->flash->error('Menu not found.');

            return Response::redirect('/admin/menus');
        }

        $old = $this->flash->pullOldInput();
        $menuState = $this->menuStateFromMenu($menu);

        if (isset($old['name']) && is_string($old['name'])) {
            $menuState['name'] = $old['name'];
        }

        if (isset($old['items']) && is_array($old['items'])) {
            $menuState['items'] = $this->normalizeEditorItems($old['items']);
        }

        if (isset($old['next_item_index'])) {
            $menuState['next_item_index'] = max((int) $old['next_item_index'], count($menuState['items']) + 1, 1);
        }

        $content = $this->view->renderMenuEditorPage(
            $menuState,
            $this->menus->pageOptions(),
            $this->csrf->inputName(),
            $this->csrf->token(),
        );

        return Response::html($this->view->layout('Edit Menu', $content, AdminNavigation::items(), $this->flash->pull()));
    }

    public function update(Request $request, array $parameters = []): Response
    {
        if (!$this->auth->check()) {
            $this->flash->error('Please sign in to manage menus.');

            return Response::redirect('/admin/login');
        }

        if (!$this->csrf->verifyToken((string) $request->input($this->csrf->inputName(), ''))) {
            $this->flash->error('CSRF verification failed.');

            return Response::redirect('/admin/menus');
        }

        $menuId = (int) ($parameters['id'] ?? 0);
        $menu = $this->menus->findMenu($menuId);

        if ($menu === null) {
            $this->flash->error('Menu not found.');

            return Response::redirect('/admin/menus');
        }

        $name = $this->sanitizer->text((string) $request->input('name', ''));
        $items = $this->requestItems($request);
        $nextItemIndex = max((int) $request->input('next_item_index', count($items) + 1), count($items) + 1, 1);

        if ($request->input('add_item') !== null) {
            $items[] = $this->blankItem('new-' . $nextItemIndex);

            return $this->renderEditor($menuId, $name, $items, $nextItemIndex + 1);
        }

        $removeTarget = trim((string) $request->input('remove_item', ''));

        if ($removeTarget !== '') {
            $items = $this->removeItem($items, $removeTarget);

            return $this->renderEditor($menuId, $name, $items, $nextItemIndex);
        }

        $moveUpTarget = trim((string) $request->input('move_up_item', ''));

        if ($moveUpTarget !== '') {
            $items = $this->moveItem($items, $moveUpTarget, -1);

            return $this->renderEditor($menuId, $name, $items, $nextItemIndex);
        }

        $moveDownTarget = trim((string) $request->input('move_down_item', ''));

        if ($moveDownTarget !== '') {
            $items = $this->moveItem($items, $moveDownTarget, 1);

            return $this->renderEditor($menuId, $name, $items, $nextItemIndex);
        }

        try {
            $this->menus->updateMenu($menuId, $name, $items);
            $this->flash->success('Menu updated.');
        } catch (RuntimeException $exception) {
            $this->flash->withOldInput([
                'name' => $name,
                'items' => $items,
                'next_item_index' => $nextItemIndex,
            ]);
            $this->flash->error($exception->getMessage());
        }

        return Response::redirect('/admin/menus/' . $menuId . '/edit');
    }

    /**
     * @param array<string, mixed> $menu
     * @return array<string, mixed>
     */
    private function menuStateFromMenu(array $menu): array
    {
        $items = [];
        $rowIdsById = [];

        foreach ((array) ($menu['items'] ?? []) as $item) {
            $rowId = 'item-' . (int) ($item['id'] ?? 0);
            $rowIdsById[(int) ($item['id'] ?? 0)] = $rowId;
            $items[] = [
                'row_id' => $rowId,
                'label' => (string) ($item['label'] ?? ''),
                'link_type' => isset($item['page_id']) && $item['page_id'] !== null ? 'page' : 'custom_url',
                'page_id' => $item['page_id'] !== null ? (string) $item['page_id'] : '',
                'url' => (string) ($item['url'] ?? ''),
                'target' => (string) ($item['target'] ?? '_self'),
                'parent_row_id' => '',
            ];
        }

        foreach ($items as $index => $item) {
            $parentId = (int) (((array) ($menu['items'] ?? []))[$index]['parent_id'] ?? 0);
            $items[$index]['parent_row_id'] = $parentId > 0 ? (string) ($rowIdsById[$parentId] ?? '') : '';
        }

        return [
            'id' => (int) $menu['id'],
            'name' => (string) $menu['name'],
            'items' => $items,
            'next_item_index' => count($items) + 1,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, string>>
     */
    private function normalizeEditorItems(array $items): array
    {
        $normalized = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $rowId = trim((string) ($item['row_id'] ?? ''));

            if ($rowId === '') {
                continue;
            }

            $normalized[] = [
                'row_id' => $rowId,
                'label' => $this->sanitizer->text((string) ($item['label'] ?? '')),
                'link_type' => in_array((string) ($item['link_type'] ?? 'page'), ['page', 'custom_url'], true) ? (string) $item['link_type'] : 'page',
                'page_id' => trim((string) ($item['page_id'] ?? '')),
                'url' => trim((string) ($item['url'] ?? '')),
                'target' => (string) ($item['target'] ?? '_self'),
                'parent_row_id' => trim((string) ($item['parent_row_id'] ?? '')),
            ];
        }

        return $normalized;
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    private function requestItems(Request $request): array
    {
        $rowIds = (array) $request->input('item_row_id', []);
        $labels = (array) $request->input('item_label', []);
        $linkTypes = (array) $request->input('item_link_type', []);
        $pageIds = (array) $request->input('item_page_id', []);
        $urls = (array) $request->input('item_url', []);
        $targets = (array) $request->input('item_target', []);
        $parents = (array) $request->input('item_parent_row_id', []);
        $items = [];

        foreach ($rowIds as $index => $rowId) {
            $rowId = trim((string) $rowId);

            if ($rowId === '') {
                continue;
            }

            $items[] = [
                'client_id' => $rowId,
                'label' => $this->sanitizer->text((string) ($labels[$index] ?? '')),
                'link_type' => (string) ($linkTypes[$index] ?? 'page'),
                'page_id' => trim((string) ($pageIds[$index] ?? '')),
                'url' => trim((string) ($urls[$index] ?? '')),
                'target' => (string) ($targets[$index] ?? '_self'),
                'parent_ref' => trim((string) ($parents[$index] ?? '')) ?: null,
                'row_id' => $rowId,
                'parent_row_id' => trim((string) ($parents[$index] ?? '')),
            ];
        }

        return $items;
    }

    /**
     * @param array<int, array<string, string|null>> $items
     */
    private function renderEditor(int $menuId, string $name, array $items, int $nextItemIndex): Response
    {
        $content = $this->view->renderMenuEditorPage(
            [
                'id' => $menuId,
                'name' => $name,
                'items' => array_map(function (array $item): array {
                    return [
                        'row_id' => (string) ($item['row_id'] ?? $item['client_id'] ?? ''),
                        'label' => (string) ($item['label'] ?? ''),
                        'link_type' => (string) ($item['link_type'] ?? 'page'),
                        'page_id' => (string) ($item['page_id'] ?? ''),
                        'url' => (string) ($item['url'] ?? ''),
                        'target' => (string) ($item['target'] ?? '_self'),
                        'parent_row_id' => (string) ($item['parent_row_id'] ?? $item['parent_ref'] ?? ''),
                    ];
                }, $items),
                'next_item_index' => $nextItemIndex,
            ],
            $this->menus->pageOptions(),
            $this->csrf->inputName(),
            $this->csrf->token(),
        );

        return Response::html($this->view->layout('Edit Menu', $content, AdminNavigation::items(), $this->flash->pull()));
    }

    /**
     * @param array<int, array<string, string|null>> $items
     * @return array<int, array<string, string|null>>
     */
    private function removeItem(array $items, string $rowId): array
    {
        $filtered = array_values(array_filter($items, static fn (array $item): bool => (string) ($item['row_id'] ?? '') !== $rowId));

        foreach ($filtered as &$item) {
            if ((string) ($item['parent_row_id'] ?? '') === $rowId || (string) ($item['parent_ref'] ?? '') === $rowId) {
                $item['parent_row_id'] = '';
                $item['parent_ref'] = null;
            }
        }
        unset($item);

        return $filtered;
    }

    /**
     * @param array<int, array<string, string|null>> $items
     * @return array<int, array<string, string|null>>
     */
    private function moveItem(array $items, string $rowId, int $direction): array
    {
        $index = null;

        foreach ($items as $position => $item) {
            if ((string) ($item['row_id'] ?? '') === $rowId) {
                $index = $position;
                break;
            }
        }

        if ($index === null) {
            return $items;
        }

        $target = $index + $direction;

        if ($target < 0 || $target >= count($items)) {
            return $items;
        }

        $current = $items[$index];
        $items[$index] = $items[$target];
        $items[$target] = $current;

        return array_values($items);
    }

    /**
     * @return array{row_id: string, label: string, link_type: string, page_id: string, url: string, target: string, parent_row_id: string}
     */
    private function blankItem(string $rowId): array
    {
        return [
            'row_id' => $rowId,
            'label' => '',
            'link_type' => 'page',
            'page_id' => '',
            'url' => '',
            'target' => '_self',
            'parent_row_id' => '',
        ];
    }
}
