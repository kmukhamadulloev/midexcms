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
use PlainCMS\Core\SettingsService;
use PlainCMS\Core\Validator;

final class SettingsController
{
    public function __construct(
        private readonly Auth $auth,
        private readonly Csrf $csrf,
        private readonly Flash $flash,
        private readonly Validator $validator,
        private readonly Sanitizer $sanitizer,
        private readonly SettingsService $settings,
        private readonly AdminView $view,
    ) {
    }

    public function index(Request $request, array $parameters = []): Response
    {
        if (!$this->auth->check()) {
            $this->flash->error('Please sign in to access settings.');

            return Response::redirect('/admin/login');
        }

        $persisted = $this->settings->formData();
        $old = $this->flash->pullOldInput();
        $errors = $this->flash->pullErrors();
        $values = $old === [] ? $persisted : array_merge($persisted, $this->normalizeOldInput($old));
        $websiteThemes = $this->settings->availableWebsiteThemes();
        $dashboardThemes = $this->settings->availableDashboardThemes();
        $flash = $this->flash->pull();

        return Response::html($this->view->layout(
            'Settings',
            $this->view->renderSettingsPage($values, $websiteThemes, $dashboardThemes, $errors, $this->csrf->inputName(), $this->csrf->token()),
            AdminNavigation::items(),
            $flash,
        ));
    }

    public function update(Request $request, array $parameters = []): Response
    {
        if (!$this->auth->check()) {
            $this->flash->error('Please sign in to update settings.');

            return Response::redirect('/admin/login');
        }

        if (!$this->csrf->verifyToken((string) $request->input($this->csrf->inputName(), ''))) {
            $this->flash->error('CSRF verification failed.');

            return Response::redirect('/admin/settings');
        }

        $action = (string) $request->input('action', 'save');

        if ($action === 'clear_cache') {
            $this->settings->clearCache();
            $this->flash->success('Application cache has been cleared.');

            return Response::redirect('/admin/settings');
        }

        $values = [
            'site_title' => $this->sanitizer->text((string) $request->input('site_title', '')),
            'site_description' => $this->sanitizer->text((string) $request->input('site_description', '')),
            'dashboard_theme_active' => trim((string) $request->input('dashboard_theme_active', 'default')),
            'theme_active' => trim((string) $request->input('theme_active', 'default')),
            'comments_enabled' => $request->input('comments_enabled') === '1',
            'comments_moderation_required' => $request->input('comments_moderation_required') === '1',
            'uploads_url' => trim((string) $request->input('uploads_url', '')),
            'uploads_max_size_mb' => (string) $request->input('uploads_max_size_mb', '10'),
            'cache_driver' => trim((string) $request->input('cache_driver', 'file')),
        ];

        $dashboardThemeKeys = array_map(static fn (array $theme): string => $theme['key'], $this->settings->availableDashboardThemes());
        $themeKeys = array_map(static fn (array $theme): string => $theme['key'], $this->settings->availableWebsiteThemes());
        $errors = $this->validator->validate($values, [
            'site_title' => ['required', 'string', 'max:190'],
            'site_description' => ['required', 'string', 'max:500'],
            'dashboard_theme_active' => ['required', 'string', 'in:' . implode(',', $dashboardThemeKeys)],
            'theme_active' => ['required', 'string', 'in:' . implode(',', $themeKeys)],
            'uploads_url' => ['required', 'string', 'max:500'],
            'uploads_max_size_mb' => ['required', 'string'],
            'cache_driver' => ['required', 'string', 'in:file,null'],
        ]);

        if (!ctype_digit($values['uploads_max_size_mb']) || (int) $values['uploads_max_size_mb'] < 1 || (int) $values['uploads_max_size_mb'] > 100) {
            $errors['uploads_max_size_mb'][] = 'Upload max size must be a whole number between 1 and 100.';
        }

        if (!str_starts_with($values['uploads_url'], '/')) {
            $errors['uploads_url'][] = 'Uploads URL must start with /.';
        }

        if ($errors !== []) {
            $this->flash->withErrors($errors);
            $this->flash->withOldInput($values);
            $this->flash->error('Please fix the settings form errors.');

            return Response::redirect('/admin/settings');
        }

        $this->settings->save([
            ...$values,
            'uploads_max_size_mb' => (int) $values['uploads_max_size_mb'],
        ]);

        $this->flash->success('Settings have been updated.');

        return Response::redirect('/admin/settings');
    }
    /**
     * @param array<string, mixed> $old
     * @return array<string, mixed>
     */
    private function normalizeOldInput(array $old): array
    {
        if (isset($old['comments_enabled'])) {
            $old['comments_enabled'] = $old['comments_enabled'] === true || $old['comments_enabled'] === '1';
        }

        if (isset($old['comments_moderation_required'])) {
            $old['comments_moderation_required'] = $old['comments_moderation_required'] === true || $old['comments_moderation_required'] === '1';
        }

        return $old;
    }
}
