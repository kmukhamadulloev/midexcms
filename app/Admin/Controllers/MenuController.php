<?php

declare(strict_types=1);

namespace PlainCMS\Admin\Controllers;

use PlainCMS\Core\Admin\AdminNavigation;
use PlainCMS\Core\Admin\AdminView;
use PlainCMS\Core\Auth;
use PlainCMS\Core\Csrf;
use PlainCMS\Core\Flash;
use PlainCMS\Core\Request;
use PlainCMS\Core\Response;
use PlainCMS\Core\Sanitizer;
use PlainCMS\Modules\Menus\MenuService;
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

        $content = $this->view->renderMenuEditorPage(
            $menu,
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

        $items = [];
        $labels = (array) $request->input('item_label', []);
        $pageIds = (array) $request->input('item_page_id', []);
        $urls = (array) $request->input('item_url', []);
        $targets = (array) $request->input('item_target', []);
        $sortOrders = (array) $request->input('item_sort_order', []);
        $parents = (array) $request->input('item_parent_index', []);

        foreach ($labels as $index => $label) {
            $parentIndex = $parents[$index] ?? '';
            $items[] = [
                'label' => $this->sanitizer->text((string) $label),
                'page_id' => $pageIds[$index] ?? '',
                'url' => trim((string) ($urls[$index] ?? '')),
                'target' => (string) ($targets[$index] ?? '_self'),
                'sort_order' => $sortOrders[$index] ?? 0,
                'parent_ref' => $parentIndex !== '' ? (int) $parentIndex : null,
                'row_index' => $index + 1,
            ];
        }

        try {
            $this->menus->updateMenu($menuId, $this->sanitizer->text((string) $request->input('name', '')), $items);
            $this->flash->success('Menu updated.');
        } catch (RuntimeException $exception) {
            $this->flash->error($exception->getMessage());
        }

        return Response::redirect('/admin/menus/' . $menuId . '/edit');
    }
}
