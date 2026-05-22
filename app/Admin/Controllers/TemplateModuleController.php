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
use MidexCMS\Core\SettingsService;
use MidexCMS\Modules\Menus\MenuService;
use RuntimeException;

final class TemplateModuleController
{
    public function __construct(
        private readonly Auth $auth,
        private readonly Csrf $csrf,
        private readonly Flash $flash,
        private readonly SettingsService $settings,
        private readonly MenuService $menus,
        private readonly AdminView $view,
    ) {
    }

    public function index(Request $request, array $parameters = []): Response
    {
        if (!$this->auth->check()) {
            $this->flash->error('Please sign in to manage template modules.');

            return Response::redirect('/admin/login');
        }

        $themeKey = (string) $this->settings->get('theme.active', 'default');
        $content = $this->view->renderTemplateModulesPage(
            $themeKey,
            $this->themeName($themeKey),
            $this->menus->menuSlotsForTheme($themeKey),
            $this->menus->listMenus(),
            $this->menus->placementsForTheme($themeKey),
            $this->csrf->inputName(),
            $this->csrf->token(),
        );

        return Response::html($this->view->layout('Template Modules', $content, AdminNavigation::items(), $this->flash->pull()));
    }

    public function update(Request $request, array $parameters = []): Response
    {
        if (!$this->auth->check()) {
            $this->flash->error('Please sign in to manage template modules.');

            return Response::redirect('/admin/login');
        }

        if (!$this->csrf->verifyToken((string) $request->input($this->csrf->inputName(), ''))) {
            $this->flash->error('CSRF verification failed.');

            return Response::redirect('/admin/template-modules');
        }

        $themeKey = (string) $this->settings->get('theme.active', 'default');
        $slotKey = trim((string) $request->input('slot_key', ''));
        $menuIdRaw = trim((string) $request->input('menu_id', ''));
        $menuId = $menuIdRaw !== '' && ctype_digit($menuIdRaw) ? (int) $menuIdRaw : null;

        try {
            if ($slotKey === '') {
                throw new RuntimeException('Template slot is required.');
            }

            $this->menus->assignMenuToSlot($themeKey, $slotKey, $menuId);
            $this->flash->success($menuId === null ? 'Slot assignment cleared.' : 'Template slot updated.');
        } catch (RuntimeException $exception) {
            $this->flash->error($exception->getMessage());
        }

        return Response::redirect('/admin/template-modules');
    }

    private function themeName(string $themeKey): string
    {
        foreach ($this->settings->availableWebsiteThemes() as $theme) {
            if ((string) $theme['key'] === $themeKey) {
                return (string) $theme['name'];
            }
        }

        return $themeKey;
    }
}
